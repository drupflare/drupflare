<?php

/**
 * Edits images through the host's image engine.
 *
 * The editor holds the encoded source and a queue of operations, and the host applies the queue
 * and encodes the result in one request when a file is written. Every operation either queues or
 * answers a WP_Error that says why it cannot.
 */
class Drupflare_Image_Editor extends WP_Image_Editor
{
	/** @var string|null the encoded source */
	protected $bytes;

	/** @var array<int, array<string, mixed>> the operations queued on the source */
	protected $ops = [];

	public static function test($args = [])
	{
		return drupflare_image_available();
	}

	public static function supports_mime_type($mime_type)
	{
		return isset(DRUPFLARE_IMAGE_FORMATS[$mime_type]);
	}

	public function load()
	{
		if ($this->bytes !== null) {
			return true;
		}
		if (!is_file($this->file)) {
			return new WP_Error('error_loading_image', __('File does not exist?'), $this->file);
		}
		$contents = @file_get_contents($this->file);
		if (!$contents) {
			return new WP_Error('error_loading_image', __('File does not exist?'), $this->file);
		}
		if (strlen($contents) > drupflare_image_max_bytes()) {
			return new WP_Error(
				'image_too_large',
				sprintf(
					'drupflare edits images up to %d bytes, and this one is %d.',
					drupflare_image_max_bytes(),
					strlen($contents),
				),
				$this->file,
			);
		}
		$size = wp_getimagesize($this->file);
		if (!$size) {
			return new WP_Error('invalid_image', __('Could not read image size.'), $this->file);
		}
		if (!isset(DRUPFLARE_IMAGE_FORMATS[$size['mime']])) {
			return new WP_Error(
				'invalid_image',
				sprintf('drupflare does not edit %s images.', $size['mime']),
				$this->file,
			);
		}
		$this->bytes = $contents;
		$this->ops = [];
		$this->update_size($size[0], $size[1]);
		$this->mime_type = $size['mime'];
		return $this->set_quality();
	}

	/**
	 * The operations that take the current image to a resized one.
	 *
	 * @return array{ops: array<int, array<string, mixed>>, width: int, height: int}|WP_Error
	 */
	protected function plan_resize($max_w, $max_h, $crop)
	{
		$dims = image_resize_dimensions(
			$this->size['width'],
			$this->size['height'],
			$max_w,
			$max_h,
			$crop,
		);
		if (!$dims) {
			return new WP_Error(
				'error_getting_dimensions',
				__('Could not calculate resized image dimensions'),
				$this->file,
			);
		}
		[$dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h] = $dims;
		$ops = [];
		if (
			$src_x !== 0 ||
			$src_y !== 0 ||
			$src_w !== $this->size['width'] ||
			$src_h !== $this->size['height']
		) {
			$ops[] = [
				'op' => 'crop',
				'x' => $src_x,
				'y' => $src_y,
				'width' => $src_w,
				'height' => $src_h,
			];
		}
		$ops[] = ['op' => 'resize', 'width' => $dst_w, 'height' => $dst_h];
		return ['ops' => $ops, 'width' => $dst_w, 'height' => $dst_h];
	}

	public function resize($max_w, $max_h, $crop = false)
	{
		if ($this->size['width'] === $max_w && $this->size['height'] === $max_h) {
			return true;
		}
		$plan = $this->plan_resize($max_w, $max_h, $crop);
		if (is_wp_error($plan)) {
			return $plan;
		}
		$this->set_quality(null, ['width' => $plan['width'], 'height' => $plan['height']]);
		$this->ops = array_merge($this->ops, $plan['ops']);
		$this->update_size($plan['width'], $plan['height']);
		return true;
	}

	public function multi_resize($sizes)
	{
		$metadata = [];
		foreach ($sizes as $size => $size_data) {
			$meta = $this->make_subsize($size_data);
			if (!is_wp_error($meta)) {
				$metadata[$size] = $meta;
			}
		}
		return $metadata;
	}

	public function make_subsize($size_data)
	{
		if (!isset($size_data['width']) && !isset($size_data['height'])) {
			return new WP_Error(
				'image_subsize_create_error',
				__('Cannot resize the image. Both width and height are not set.'),
			);
		}
		$plan = $this->plan_resize(
			$size_data['width'] ?? null,
			$size_data['height'] ?? null,
			$size_data['crop'] ?? false,
		);
		if (is_wp_error($plan)) {
			return $plan;
		}
		$this->set_quality(null, ['width' => $plan['width'], 'height' => $plan['height']]);
		// the file name carries the size, so the editor holds the new one while it writes
		$orig_size = $this->size;
		$this->update_size($plan['width'], $plan['height']);
		$saved = $this->write(array_merge($this->ops, $plan['ops']), null, null);
		$this->size = $orig_size;
		if (!is_wp_error($saved)) {
			unset($saved['path']);
		}
		return $saved;
	}

	public function crop(
		$src_x,
		$src_y,
		$src_w,
		$src_h,
		$dst_w = null,
		$dst_h = null,
		$src_abs = false,
	) {
		if (!$dst_w) {
			$dst_w = $src_w;
		}
		if (!$dst_h) {
			$dst_h = $src_h;
		}
		foreach ([$src_w, $src_h, $dst_w, $dst_h] as $value) {
			if (!is_numeric($value) || (int) $value <= 0) {
				return new WP_Error('image_crop_error', __('Image crop failed.'), $this->file);
			}
		}
		if ($src_abs) {
			$src_w -= $src_x;
			$src_h -= $src_y;
		}
		[$x, $y, $w, $h] = [(int) $src_x, (int) $src_y, (int) $src_w, (int) $src_h];
		if (
			$x < 0 ||
			$y < 0 ||
			$w <= 0 ||
			$h <= 0 ||
			$x + $w > $this->size['width'] ||
			$y + $h > $this->size['height']
		) {
			return new WP_Error(
				'image_crop_error',
				sprintf(
					'The crop %dx%d at %d,%d is outside the %dx%d image.',
					$w,
					$h,
					$x,
					$y,
					$this->size['width'],
					$this->size['height'],
				),
				$this->file,
			);
		}
		$this->ops[] = ['op' => 'crop', 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
		if ((int) $dst_w !== $w || (int) $dst_h !== $h) {
			$this->ops[] = ['op' => 'resize', 'width' => (int) $dst_w, 'height' => (int) $dst_h];
		}
		$this->update_size((int) $dst_w, (int) $dst_h);
		return true;
	}

	public function rotate($angle)
	{
		$turn = fmod(fmod((float) $angle, 360) + 360, 360);
		if ($turn === 0.0) {
			return true;
		}
		$width = $this->size['width'];
		$height = $this->size['height'];
		if (fmod($turn, 90) === 0.0) {
			$swap = fmod($turn, 180) === 90.0;
			$out = $swap ? [$height, $width] : [$width, $height];
		} else {
			$rad = deg2rad($turn);
			$out = [
				(int) ceil(abs($width * cos($rad)) + abs($height * sin($rad)) - 1e-9),
				(int) ceil(abs($width * sin($rad)) + abs($height * cos($rad)) - 1e-9),
			];
		}
		// the host turns clockwise and WordPress counter-clockwise; the corners are clear white
		$this->ops[] = [
			'op' => 'rotate',
			'degrees' => fmod(360 - $turn, 360),
			'background' => 2147483647,
			'width' => $out[0],
			'height' => $out[1],
		];
		$this->update_size($out[0], $out[1]);
		return true;
	}

	public function flip($horz, $vert)
	{
		// WordPress names the axis the line lies on, so $horz turns the rows over
		$mode = ($horz ? 2 : 0) | ($vert ? 1 : 0);
		if ($mode !== 0) {
			$this->ops[] = ['op' => 'flip', 'mode' => $mode];
		}
		return true;
	}

	public function save($destfilename = null, $mime_type = null)
	{
		$saved = $this->write($this->ops, $destfilename, $mime_type);
		if (!is_wp_error($saved)) {
			$this->file = $saved['path'];
			$this->mime_type = $saved['mime-type'];
		}
		return $saved;
	}

	public function stream($mime_type = null)
	{
		[$filename, $extension, $mime_type] = $this->get_output_format(null, $mime_type);
		$encoded = $this->encode($this->ops, $mime_type);
		if (is_wp_error($encoded)) {
			return false;
		}
		header('Content-Type: ' . $mime_type);
		echo $encoded['bytes'];
		return true;
	}

	/**
	 * @param array<int, array<string, mixed>> $ops
	 *
	 * @return array{bytes: string, width: int, height: int}|WP_Error
	 */
	protected function encode(array $ops, string $mime_type)
	{
		if ($this->bytes === null) {
			return new WP_Error(
				'image_save_error',
				__('Image Editor Save Failed') . ': nothing is loaded.',
			);
		}
		$format = DRUPFLARE_IMAGE_FORMATS[$mime_type] ?? null;
		if ($format === null) {
			return new WP_Error(
				'image_save_error',
				__('Image Editor Save Failed') .
					sprintf(': drupflare does not write %s.', $mime_type),
			);
		}
		$progressive = (bool) apply_filters('image_save_progressive', false, $mime_type);
		if ($progressive) {
			drupflare_image_note_progressive($mime_type);
		}
		$result = drupflare_image_render(
			$this->bytes,
			$ops,
			$format,
			$this->get_quality(),
			$progressive && $mime_type === 'image/jpeg',
		);
		if (is_string($result)) {
			return new WP_Error(
				'image_save_error',
				__('Image Editor Save Failed') . ': ' . $result,
			);
		}
		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $ops
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	protected function write(array $ops, $filename, $mime_type)
	{
		[$filename, $extension, $mime_type] = $this->get_output_format($filename, $mime_type);
		if (!$filename) {
			$filename = $this->generate_filename(null, null, $extension);
		}
		$encoded = $this->encode($ops, $mime_type);
		if (is_wp_error($encoded)) {
			return $encoded;
		}
		if (!wp_is_stream($filename)) {
			wp_mkdir_p(dirname($filename));
		}
		if (@file_put_contents($filename, $encoded['bytes']) === false) {
			return new WP_Error(
				'image_save_error',
				__('Image Editor Save Failed') . ': the file could not be written.',
			);
		}
		if (!wp_is_stream($filename)) {
			$stat = stat(dirname($filename));
			@chmod($filename, $stat['mode'] & 0000666);
		}
		return [
			'path' => $filename,
			'file' => wp_basename(apply_filters('image_make_intermediate_size', $filename)),
			'width' => $encoded['width'],
			'height' => $encoded['height'],
			'mime-type' => $mime_type,
			'filesize' => wp_filesize($filename),
		];
	}
}
