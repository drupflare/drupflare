<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;
use Drupal\drupflare\Http\Park;
use ValueError;

/**
 * The gd functions a CMS upload path uses, over the host's image engine.
 *
 * The gd extension is not compiled in. A handle keeps the encoded source and a queue of crop,
 * resize and rotate operations ({@see GdImage}); writing the image out parks PHP on
 * `cfwpark+image://` and the host decodes, applies the queue and re-encodes. No pixel is readable
 * or writable from PHP, so the per-pixel and drawing functions stay undefined and
 * `extension_loaded('gd')` stays false.
 *
 * What a queue can express is a copy of a region onto a fresh canvas of exactly the target size,
 * `imagescale`, `imagecrop` inside the bounds and `imagerotate`. Any other copy, such as painting
 * onto an existing image or at an offset, answers false and records a degradation.
 */
final class Gd
{
	/**
	 * The park scheme; must match `PARK_IMAGE_SCHEME` in the host's `park-drive.ts`.
	 *
	 * @var string
	 */
	public const SCHEME = 'cfwpark+image://';

	/**
	 * The resampling and format constants of gd, which the runtime defines because there is no gd.
	 *
	 * @var array<string, int>
	 */
	public const CONSTANTS = [
		'IMG_GIF' => 1,
		'IMG_JPG' => 2,
		'IMG_JPEG' => 2,
		'IMG_PNG' => 4,
		'IMG_WEBP' => 32,
		'IMG_BELL' => 1,
		'IMG_BESSEL' => 2,
		'IMG_BILINEAR_FIXED' => 3,
		'IMG_BICUBIC' => 4,
		'IMG_BICUBIC_FIXED' => 5,
		'IMG_BLACKMAN' => 6,
		'IMG_BOX' => 7,
		'IMG_BSPLINE' => 8,
		'IMG_CATMULLROM' => 9,
		'IMG_GAUSSIAN' => 10,
		'IMG_GENERALIZED_CUBIC' => 11,
		'IMG_HERMITE' => 12,
		'IMG_HAMMING' => 13,
		'IMG_HANNING' => 14,
		'IMG_MITCHELL' => 15,
		'IMG_NEAREST_NEIGHBOUR' => 16,
		'IMG_POWER' => 17,
		'IMG_QUADRATIC' => 18,
		'IMG_SINC' => 19,
		'IMG_TRIANGLE' => 20,
		'IMG_WEIGHTED4' => 21,
	];

	/**
	 * Whether the host answers image parks.
	 *
	 * @return bool
	 *   True with a park and the `cfwParkImage` flag.
	 */
	public static function available(): bool
	{
		return Park::available() && Host::flag('cfwParkImage');
	}

	// #region reading

	/**
	 * Reads an image from bytes; the source stays encoded.
	 *
	 * @param string $data
	 *   The encoded image.
	 *
	 * @return GdImage|false
	 *   A handle, or false when the bytes are not a JPEG, PNG, GIF or WebP.
	 */
	public static function imagecreatefromstring(string $data): GdImage|false
	{
		return self::load($data, null);
	}

	/**
	 * Reads a JPEG image; the source stays encoded.
	 *
	 * @param string $filename
	 *   The file to read.
	 *
	 * @return GdImage|false
	 *   A handle, or false for a missing file or another format.
	 */
	public static function imagecreatefromjpeg(string $filename): GdImage|false
	{
		return self::fromFile($filename, IMAGETYPE_JPEG);
	}

	/**
	 * Reads a PNG image; the source stays encoded.
	 *
	 * @param string $filename
	 *   The file to read.
	 *
	 * @return GdImage|false
	 *   A handle, or false for a missing file or another format.
	 */
	public static function imagecreatefrompng(string $filename): GdImage|false
	{
		return self::fromFile($filename, IMAGETYPE_PNG);
	}

	/**
	 * Reads a WebP image; the source stays encoded.
	 *
	 * @param string $filename
	 *   The file to read.
	 *
	 * @return GdImage|false
	 *   A handle, or false for a missing file or another format.
	 */
	public static function imagecreatefromwebp(string $filename): GdImage|false
	{
		return self::fromFile($filename, IMAGETYPE_WEBP);
	}

	/**
	 * Reads a GIF image; the source stays encoded.
	 *
	 * @param string $filename
	 *   The file to read.
	 *
	 * @return GdImage|false
	 *   A handle, or false for a missing file or another format.
	 */
	public static function imagecreatefromgif(string $filename): GdImage|false
	{
		return self::fromFile($filename, IMAGETYPE_GIF);
	}

	/**
	 * Makes a blank canvas of the given size.
	 *
	 * @param int $width
	 *   The width in pixels.
	 * @param int $height
	 *   The height in pixels.
	 *
	 * @return GdImage
	 *   The canvas.
	 *
	 * @throws ValueError
	 *   For a size below 1.
	 */
	public static function imagecreatetruecolor(int $width, int $height): GdImage
	{
		if ($width <= 0) {
			throw new ValueError(
				'imagecreatetruecolor(): Argument #1 ($width) must be greater than 0',
			);
		}
		if ($height <= 0) {
			throw new ValueError(
				'imagecreatetruecolor(): Argument #2 ($height) must be greater than 0',
			);
		}
		return new GdImage(null, $width, $height);
	}

	/**
	 * Reads the tracked width.
	 *
	 * @param GdImage $image
	 *   The image.
	 *
	 * @return int
	 *   The width in pixels.
	 */
	public static function imagesx(GdImage $image): int
	{
		return $image->width;
	}

	/**
	 * Reads the tracked height.
	 *
	 * @param GdImage $image
	 *   The image.
	 *
	 * @return int
	 *   The height in pixels.
	 */
	public static function imagesy(GdImage $image): int
	{
		return $image->height;
	}

	/**
	 * Does nothing, as gd has since PHP 8.
	 *
	 * @param GdImage $image
	 *   The image.
	 *
	 * @return bool
	 *   Always true.
	 */
	public static function imagedestroy(GdImage $image): bool
	{
		return true;
	}

	/**
	 * Stores the alpha blending flag on the handle.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param bool $enable
	 *   Whether blending is on.
	 *
	 * @return bool
	 *   Always true.
	 */
	public static function imagealphablending(GdImage $image, bool $enable): bool
	{
		$image->blend = $enable;
		return true;
	}

	/**
	 * Stores the save-alpha flag on the handle.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param bool $enable
	 *   Whether the alpha channel is written.
	 *
	 * @return bool
	 *   Always true.
	 */
	public static function imagesavealpha(GdImage $image, bool $enable): bool
	{
		$image->saveAlpha = $enable;
		return true;
	}

	/**
	 * Reads a file into a handle of an expected type.
	 *
	 * @param string $filename
	 *   The file to read.
	 * @param int $type
	 *   The IMAGETYPE constant it must be.
	 *
	 * @return GdImage|false
	 *   A handle, or false.
	 */
	private static function fromFile(string $filename, int $type): GdImage|false
	{
		$bytes = @file_get_contents($filename);
		return $bytes === false ? false : self::load($bytes, $type);
	}

	/**
	 * Builds a handle from encoded bytes.
	 *
	 * @param string $bytes
	 *   The encoded image.
	 * @param int|null $type
	 *   The IMAGETYPE constant it must be, or null for any served type.
	 *
	 * @return GdImage|false
	 *   A handle, or false.
	 */
	private static function load(string $bytes, ?int $type): GdImage|false
	{
		$info = @getimagesizefromstring($bytes);
		if (
			!is_array($info) ||
			!in_array(
				$info[2],
				[IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP],
				true,
			)
		) {
			return false;
		}
		if ($type !== null && $info[2] !== $type) {
			return false;
		}
		return new GdImage($bytes, $info[0], $info[1]);
	}

	// #endregion

	// #region geometry

	/**
	 * Queues a bilinear copy of a source region over a fresh canvas.
	 *
	 * @param GdImage $dst_image
	 *   The target canvas.
	 * @param GdImage $src_image
	 *   The source image.
	 * @param int $dst_x
	 *   The target x offset; only 0 is served.
	 * @param int $dst_y
	 *   The target y offset; only 0 is served.
	 * @param int $src_x
	 *   The source rectangle x.
	 * @param int $src_y
	 *   The source rectangle y.
	 * @param int $dst_width
	 *   The target width; the whole canvas is served.
	 * @param int $dst_height
	 *   The target height; the whole canvas is served.
	 * @param int $src_width
	 *   The source rectangle width.
	 * @param int $src_height
	 *   The source rectangle height.
	 *
	 * @return bool
	 *   True when queued; false and a recorded degradation for a copy the queue cannot express.
	 */
	public static function imagecopyresampled(
		GdImage $dst_image,
		GdImage $src_image,
		int $dst_x,
		int $dst_y,
		int $src_x,
		int $src_y,
		int $dst_width,
		int $dst_height,
		int $src_width,
		int $src_height,
	): bool {
		return self::copy('imagecopyresampled', 'bilinear', $dst_image, $src_image, [
			$dst_x,
			$dst_y,
			$src_x,
			$src_y,
			$dst_width,
			$dst_height,
			$src_width,
			$src_height,
		]);
	}

	/**
	 * Queues a nearest-neighbour copy of a source region over a fresh canvas.
	 *
	 * @param GdImage $dst_image
	 *   The target canvas.
	 * @param GdImage $src_image
	 *   The source image.
	 * @param int $dst_x
	 *   The target x offset; only 0 is served.
	 * @param int $dst_y
	 *   The target y offset; only 0 is served.
	 * @param int $src_x
	 *   The source rectangle x.
	 * @param int $src_y
	 *   The source rectangle y.
	 * @param int $dst_width
	 *   The target width; the whole canvas is served.
	 * @param int $dst_height
	 *   The target height; the whole canvas is served.
	 * @param int $src_width
	 *   The source rectangle width.
	 * @param int $src_height
	 *   The source rectangle height.
	 *
	 * @return bool
	 *   True when queued; false and a recorded degradation for a copy the queue cannot express.
	 */
	public static function imagecopyresized(
		GdImage $dst_image,
		GdImage $src_image,
		int $dst_x,
		int $dst_y,
		int $src_x,
		int $src_y,
		int $dst_width,
		int $dst_height,
		int $src_width,
		int $src_height,
	): bool {
		return self::copy('imagecopyresized', 'nearest', $dst_image, $src_image, [
			$dst_x,
			$dst_y,
			$src_x,
			$src_y,
			$dst_width,
			$dst_height,
			$src_width,
			$src_height,
		]);
	}

	/**
	 * Queues a copy onto a fresh canvas.
	 *
	 * @param string $fn
	 *   The gd function, for the degradation.
	 * @param string $filter
	 *   The resize filter: bilinear or nearest.
	 * @param GdImage $dst
	 *   The target canvas.
	 * @param GdImage $src
	 *   The source image.
	 * @param int[] $r
	 *   Dst x, dst y, src x, src y, dst width, dst height, src width, src height.
	 *
	 * @return bool
	 *   True when queued.
	 */
	private static function copy(
		string $fn,
		string $filter,
		GdImage $dst,
		GdImage $src,
		array $r,
	): bool {
		[$dx, $dy, $sx, $sy, $dw, $dh, $sw, $sh] = $r;
		if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) {
			return false;
		}
		if ($dst->bytes !== null || $dst->painted) {
			return self::refuse(
				$fn,
				'the target already holds pixels, and only one copy onto a fresh canvas can be queued',
			);
		}
		if ($src->bytes === null) {
			return self::refuse($fn, 'the source is a blank canvas with nothing painted on it');
		}
		if ($dx !== 0 || $dy !== 0 || $dw !== $dst->width || $dh !== $dst->height) {
			return self::refuse(
				$fn,
				'the copy does not cover the whole target canvas from its corner',
			);
		}
		if ($sx < 0 || $sy < 0 || $sx + $sw > $src->width || $sy + $sh > $src->height) {
			return self::refuse($fn, 'the source rectangle leaves the source image');
		}
		$ops = $src->ops;
		if ($sx !== 0 || $sy !== 0 || $sw !== $src->width || $sh !== $src->height) {
			$ops[] = ['op' => 'crop', 'x' => $sx, 'y' => $sy, 'width' => $sw, 'height' => $sh];
		}
		if ($sw !== $dw || $sh !== $dh) {
			$ops[] = ['op' => 'resize', 'width' => $dw, 'height' => $dh, 'filter' => $filter];
		}
		$dst->bytes = $src->bytes;
		$dst->ops = $ops;
		$dst->painted = true;
		return true;
	}

	/**
	 * Queues a resize on a copy of the handle.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param int $width
	 *   The new width.
	 * @param int $height
	 *   The new height; -1 keeps the aspect ratio with gd's integer arithmetic.
	 * @param int $mode
	 *   The IMG_* resampling constant.
	 *
	 * @return GdImage|false
	 *   The scaled copy, or false for a blank canvas.
	 *
	 * @throws ValueError
	 *   For a width or height below 1.
	 */
	public static function imagescale(
		GdImage $image,
		int $width,
		int $height = -1,
		int $mode = 3,
	): GdImage|false {
		if ($height < 0) {
			$height = intdiv($width * $image->height, max(1, $image->width));
		}
		if ($width < 1) {
			throw new ValueError(
				'imagescale(): Argument #2 ($width) must be between 1 and 2147483647',
			);
		}
		if ($height < 1) {
			throw new ValueError(
				'imagescale(): Argument #3 ($height) must be between 1 and 2147483647',
			);
		}
		if ($image->bytes === null) {
			self::refuse('imagescale', 'the image is a blank canvas with nothing painted on it');
			return false;
		}
		$out = $image->fork();
		$out->ops[] = [
			'op' => 'resize',
			'width' => $width,
			'height' => $height,
			'filter' => $mode === Gd::CONSTANTS['IMG_NEAREST_NEIGHBOUR'] ? 'nearest' : 'bilinear',
		];
		$out->width = $width;
		$out->height = $height;
		return $out;
	}

	/**
	 * Queues a crop inside the image bounds on a copy of the handle.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param array<string, int> $rectangle
	 *   Keys x, y, width and height.
	 *
	 * @return GdImage|false
	 *   The cropped copy; false for an empty rectangle, or for one that leaves the image.
	 */
	public static function imagecrop(GdImage $image, array $rectangle): GdImage|false
	{
		$x = (int) ($rectangle['x'] ?? 0);
		$y = (int) ($rectangle['y'] ?? 0);
		$w = (int) ($rectangle['width'] ?? 0);
		$h = (int) ($rectangle['height'] ?? 0);
		if ($w <= 0 || $h <= 0) {
			return false;
		}
		if (
			$image->bytes === null ||
			$x < 0 ||
			$y < 0 ||
			$x + $w > $image->width ||
			$y + $h > $image->height
		) {
			self::refuse(
				'imagecrop',
				'the rectangle leaves the image, and gd would pad the outside',
			);
			return false;
		}
		$out = $image->fork();
		$out->ops[] = ['op' => 'crop', 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
		$out->width = $w;
		$out->height = $h;
		return $out;
	}

	/**
	 * Queues a rotation on a copy of the handle.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param float $angle
	 *   Degrees counter-clockwise, as gd takes them.
	 * @param int $background_color
	 *   The colour that fills the corners of an angled turn.
	 * @param bool $ignore_transparent
	 *   Accepted and ignored.
	 *
	 * @return GdImage|false
	 *   The rotated copy, or false for a blank canvas.
	 */
	public static function imagerotate(
		GdImage $image,
		float $angle,
		int $background_color = 0,
		bool $ignore_transparent = false,
	): GdImage|false {
		if ($image->bytes === null) {
			self::refuse('imagerotate', 'the image is a blank canvas with nothing painted on it');
			return false;
		}
		$turn = fmod(fmod($angle, 360) + 360, 360);
		$out = $image->fork();
		if ($turn === 0.0) {
			return $out;
		}
		$rad = deg2rad($turn);
		if (fmod($turn, 90) === 0.0) {
			$swap = fmod($turn, 180) === 90.0;
			$out->width = $swap ? $image->height : $image->width;
			$out->height = $swap ? $image->width : $image->height;
		} else {
			$out->width = (int) ceil(
				abs($image->width * cos($rad)) + abs($image->height * sin($rad)) - 1e-9,
			);
			$out->height = (int) ceil(
				abs($image->width * sin($rad)) + abs($image->height * cos($rad)) - 1e-9,
			);
		}
		$out->ops[] = [
			'op' => 'rotate',
			'degrees' => fmod(360 - $turn, 360),
			'background' => $background_color,
		];
		return $out;
	}

	// #endregion

	// #region writing

	/**
	 * Writes the image as a JPEG, like imagejpeg().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param resource|string|null $file
	 *   A path, a stream, or null to print the bytes.
	 * @param int $quality
	 *   From 0 to 100; -1 is 75.
	 *
	 * @return bool
	 *   True when the bytes were written.
	 */
	public static function imagejpeg(GdImage $image, $file = null, int $quality = -1): bool
	{
		return self::write(
			$image,
			'jpeg',
			$file,
			$quality < 0 ? 75 : min(100, $quality),
			'imagejpeg',
		);
	}

	/**
	 * Writes the image as a PNG, like imagepng().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param resource|string|null $file
	 *   A path, a stream, or null to print the bytes.
	 * @param int $quality
	 *   The compression level, 0 to 9; -1 is 6.
	 * @param int $filters
	 *   Accepted and ignored.
	 *
	 * @return bool
	 *   True when the bytes were written.
	 */
	public static function imagepng(
		GdImage $image,
		$file = null,
		int $quality = -1,
		int $filters = -1,
	): bool {
		return self::write($image, 'png', $file, $quality < 0 ? 6 : min(9, $quality), 'imagepng');
	}

	/**
	 * Writes the image as a WebP, like imagewebp().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param resource|string|null $file
	 *   A path, a stream, or null to print the bytes.
	 * @param int $quality
	 *   From 0 to 100; -1 is 80.
	 *
	 * @return bool
	 *   True when the bytes were written.
	 */
	public static function imagewebp(GdImage $image, $file = null, int $quality = -1): bool
	{
		return self::write(
			$image,
			'webp',
			$file,
			$quality < 0 ? 80 : min(100, $quality),
			'imagewebp',
		);
	}

	/**
	 * Writes the image as a GIF, like imagegif().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param resource|string|null $file
	 *   A path, a stream, or null to print the bytes.
	 *
	 * @return bool
	 *   True when the bytes were written.
	 */
	public static function imagegif(GdImage $image, $file = null): bool
	{
		return self::write($image, 'gif', $file, 0, 'imagegif');
	}

	/**
	 * Encodes through the host and writes the bytes where the caller asked.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param string $format
	 *   One of jpeg, png, webp or gif.
	 * @param resource|string|null $file
	 *   A path, a stream, or null to print the bytes.
	 * @param int $quality
	 *   The quality or compression level.
	 * @param string $fn
	 *   The gd function, for the degradation.
	 *
	 * @return bool
	 *   True when the bytes were written.
	 */
	private static function write(
		GdImage $image,
		string $format,
		$file,
		int $quality,
		string $fn,
	): bool {
		$bytes = self::render($image, $format, $quality, $fn);
		if ($bytes === null) {
			return false;
		}
		if ($file === null) {
			echo $bytes;
			return true;
		}
		if (is_resource($file)) {
			return fwrite($file, $bytes) === strlen($bytes);
		}
		return @file_put_contents((string) $file, $bytes) !== false;
	}

	/**
	 * Asks the host to apply the queue and encode the result.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param string $format
	 *   One of jpeg, png, webp or gif.
	 * @param int $quality
	 *   The quality or compression level.
	 * @param string $fn
	 *   The gd function, for the degradation.
	 *
	 * @return string|null
	 *   The encoded bytes, or null with a degradation recorded.
	 */
	private static function render(
		GdImage $image,
		string $format,
		int $quality,
		string $fn,
	): ?string {
		if (!self::available()) {
			self::refuse($fn, 'this deployment has no image host to run the queued operations');
			return null;
		}
		$request = [
			'source' => $image->bytes === null ? null : base64_encode($image->bytes),
			'canvas' =>
				$image->bytes === null
					? ['width' => $image->width, 'height' => $image->height]
					: null,
			'ops' => $image->ops,
			'format' => $format,
			'quality' => $quality,
		];
		$json = json_encode($request);
		$raw = $json === false ? false : Park::yieldTo(self::SCHEME . base64_encode($json));
		if (!is_string($raw)) {
			self::refuse(
				$fn,
				'the park was refused at this point in the call stack, or the request could not be encoded',
			);
			return null;
		}
		$reply = json_decode($raw, true);
		if (!is_array($reply) || isset($reply['error']) || !is_string($reply['bytes'] ?? null)) {
			self::refuse(
				$fn,
				'the host could not encode the image: ' .
					(is_array($reply)
						? (string) ($reply['error'] ?? 'no bytes in the answer')
						: 'the answer was not JSON'),
			);
			return null;
		}
		$decoded = base64_decode($reply['bytes'], true);
		if ($decoded === false) {
			self::refuse($fn, 'the host answered bytes that are not base64');
			return null;
		}
		if ($image->saveAlpha === false && $format === 'png' && self::mayHaveAlpha($image)) {
			Degradation::record(
				'gd alpha',
				'the output keeps the source alpha channel; gd drops it unless imagesavealpha() is set',
				'untested',
			);
		}
		return $decoded;
	}

	/**
	 * Whether the source is a PNG with an alpha channel.
	 *
	 * @param GdImage $image
	 *   The image.
	 *
	 * @return bool
	 *   True for colour types 4 and 6.
	 */
	private static function mayHaveAlpha(GdImage $image): bool
	{
		return $image->bytes !== null &&
			str_starts_with($image->bytes, "\x89PNG") &&
			in_array(ord($image->bytes[25] ?? "\0"), [4, 6], true);
	}

	// #endregion

	/**
	 * Records a degradation and answers false.
	 *
	 * @param string $fn
	 *   The gd function.
	 * @param string $why
	 *   The reason.
	 *
	 * @return bool
	 *   Always false.
	 */
	private static function refuse(string $fn, string $why): bool
	{
		Degradation::record($fn, $why, 'blocked');
		return false;
	}
}
