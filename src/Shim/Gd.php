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
 * The gd extension is not compiled in. A handle keeps the encoded source and a queue of
 * operations ({@see GdImage}); writing the image out parks PHP on `cfwpark+image://` and the host
 * decodes, applies the queue and re-encodes. No pixel is readable or writable from PHP, so the
 * per-pixel and drawing functions stay undefined.
 *
 * A queue expresses crop, resize, rotate, flip, a copy or merge of a region at an offset, a fill of
 * a canvas nothing has painted yet, and eight `imagefilter` types. Colors a caller allocates live
 * in a table on the handle, so `imagecolorsforindex()` and its siblings answer without the host.
 * Anything else, such as a fill that would have to read pixels, answers false and records a
 * degradation.
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
	 * The IMAGETYPE_AVIF value, a literal because the constant is PHP 8.1 and the module supports 8.3 on.
	 *
	 * @var int
	 */
	private const TYPE_AVIF = 19;

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
		'IMG_AVIF' => 256,
		'IMG_GD2_RAW' => 1,
		'IMG_GD2_COMPRESSED' => 2,
		'IMG_FLIP_HORIZONTAL' => 1,
		'IMG_FLIP_VERTICAL' => 2,
		'IMG_FLIP_BOTH' => 3,
		'IMG_FILTER_NEGATE' => 0,
		'IMG_FILTER_GRAYSCALE' => 1,
		'IMG_FILTER_BRIGHTNESS' => 2,
		'IMG_FILTER_CONTRAST' => 3,
		'IMG_FILTER_COLORIZE' => 4,
		'IMG_FILTER_EDGEDETECT' => 5,
		'IMG_FILTER_EMBOSS' => 6,
		'IMG_FILTER_GAUSSIAN_BLUR' => 7,
		'IMG_FILTER_SELECTIVE_BLUR' => 8,
		'IMG_FILTER_MEAN_REMOVAL' => 9,
		'IMG_FILTER_SMOOTH' => 10,
		'IMG_FILTER_PIXELATE' => 11,
		'IMG_FILTER_SCATTER' => 12,
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
	 * Reads an AVIF image; the source stays encoded.
	 *
	 * @param string $filename
	 *   The file to read.
	 *
	 * @return GdImage|false
	 *   A handle, or false for a missing file or another format.
	 */
	public static function imagecreatefromavif(string $filename): GdImage|false
	{
		return self::fromFile($filename, self::TYPE_AVIF);
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
		$image = new GdImage(null, $width, $height);
		$image->baseWidth = $width;
		$image->baseHeight = $height;
		return $image;
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
				[IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, self::TYPE_AVIF],
				true,
			)
		) {
			return false;
		}
		if ($type !== null && $info[2] !== $type) {
			return false;
		}
		$image = new GdImage($bytes, $info[0], $info[1]);
		$palette =
			$info[2] === IMAGETYPE_GIF || $info[2] === IMAGETYPE_PNG ? self::palette($bytes) : null;
		if ($palette !== null) {
			$image->trueColor = false;
			$image->colors = $palette['colors'];
			$image->transparent = $palette['transparent'];
			$image->headerPalette = $info[2] === IMAGETYPE_GIF;
		}
		return $image;
	}

	/**
	 * Reads the palette gd would build for a GIF or a palette PNG from the container header.
	 *
	 * This is metadata in the file, not a pixel: the GIF color table and its transparent index, or
	 * the PLTE and tRNS chunks.
	 *
	 * @param string $bytes
	 *   The encoded image.
	 *
	 * @return array{colors: array<int, array{int, int, int, int}>, transparent: int}|null
	 *   The table and the transparent index, or null for a true color file.
	 */
	private static function palette(string $bytes): ?array
	{
		return str_starts_with($bytes, 'GIF8')
			? self::gifPalette($bytes)
			: self::pngPalette($bytes);
	}

	/**
	 * Reads the first frame's color table, local if it has one, and its transparent index.
	 *
	 * @param string $bytes
	 *   The GIF.
	 *
	 * @return array{colors: array<int, array{int, int, int, int}>, transparent: int}|null
	 *   The table, or null when the file is too short to read.
	 */
	private static function gifPalette(string $bytes): ?array
	{
		$length = strlen($bytes);
		if ($length < 13) {
			return null;
		}
		$packed = ord($bytes[10]);
		$at = 13;
		$table = '';
		if ($packed & 0x80) {
			$size = 3 * (1 << (($packed & 7) + 1));
			$table = substr($bytes, $at, $size);
			$at += $size;
		}
		$transparent = -1;
		while ($at < $length) {
			$block = ord($bytes[$at]);
			if ($block === 0x21) {
				if (ord($bytes[$at + 1] ?? "\0") === 0xf9 && $transparent === -1) {
					$flags = ord($bytes[$at + 3] ?? "\0");
					if ($flags & 1) {
						$transparent = ord($bytes[$at + 6] ?? "\0");
					}
				}
				$at += 2;
				while ($at < $length && ($n = ord($bytes[$at])) !== 0) {
					$at += $n + 1;
				}
				$at++;
			} elseif ($block === 0x2c) {
				$flags = ord($bytes[$at + 9] ?? "\0");
				if ($flags & 0x80) {
					$table = substr($bytes, $at + 10, 3 * (1 << (($flags & 7) + 1)));
				}
				break;
			} else {
				break;
			}
		}
		$colors = [];
		for ($i = 0; $i + 2 < strlen($table); $i += 3) {
			$colors[] = [ord($table[$i]), ord($table[$i + 1]), ord($table[$i + 2]), 0];
		}
		if ($transparent >= 0 && isset($colors[$transparent])) {
			$colors[$transparent][3] = 127;
		} else {
			$transparent = -1;
		}
		return ['colors' => $colors, 'transparent' => $transparent];
	}

	/**
	 * Reads the PLTE and tRNS chunks of a palette PNG.
	 *
	 * @param string $bytes
	 *   The PNG.
	 *
	 * @return array{colors: array<int, array{int, int, int, int}>, transparent: int}|null
	 *   The table, or null for a PNG that is not color type 3.
	 */
	private static function pngPalette(string $bytes): ?array
	{
		if (!str_starts_with($bytes, "\x89PNG") || ord($bytes[25] ?? "\0") !== 3) {
			return null;
		}
		$plte = '';
		$trns = '';
		$at = 8;
		$length = strlen($bytes);
		while ($at + 8 <= $length) {
			$size = unpack('N', substr($bytes, $at, 4))[1];
			$name = substr($bytes, $at + 4, 4);
			if ($name === 'PLTE') {
				$plte = substr($bytes, $at + 8, $size);
			} elseif ($name === 'tRNS') {
				$trns = substr($bytes, $at + 8, $size);
			} elseif ($name === 'IDAT') {
				break;
			}
			$at += 12 + $size;
		}
		$colors = [];
		$transparent = -1;
		for ($i = 0; $i + 2 < strlen($plte); $i += 3) {
			$index = intdiv($i, 3);
			$alpha = $index < strlen($trns) ? ord($trns[$index]) : 255;
			$colors[] = [
				ord($plte[$i]),
				ord($plte[$i + 1]),
				ord($plte[$i + 2]),
				127 - ($alpha >> 1),
			];
			if ($alpha === 0 && $transparent === -1) {
				$transparent = $index;
			}
		}
		return ['colors' => $colors, 'transparent' => $transparent];
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
	 * The host picks the source pixels the way gdImageCopyResized() does.
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
		return self::copy('imagecopyresized', 'stretch', $dst_image, $src_image, [
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
	 *   The resize filter: bilinear, or stretch for libgd's imagecopyresized() sampling.
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
		if ($src->bytes === null && !$src->painted) {
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
		// a blended copy of a source that may carry alpha lands over the canvas, as gd does it
		if ($src->bytes === null || $dst->ops !== [] || ($dst->blend && !self::opaque($src))) {
			if (!$dst->trueColor) {
				return self::refuse($fn, 'the target is an indexed image, which gd would quantize');
			}
			$layer = [
				'source' => $src->bytes === null ? null : base64_encode($src->bytes),
				'canvas' =>
					$src->bytes === null
						? ['width' => $src->baseWidth, 'height' => $src->baseHeight]
						: null,
				'ops' => $ops,
			];
			$dst->ops[] = self::copyOp($layer, 0, 0, 0, 0, $dw, $dh, null, $dst->blend, $src);
			$dst->painted = true;
			return true;
		}
		$dst->bytes = $src->bytes;
		$dst->ops = $ops;
		$dst->painted = true;
		return true;
	}

	/**
	 * Whether a source is known to carry no alpha, so a blended copy equals a plain one.
	 *
	 * @param GdImage $src
	 *   The source, which holds encoded bytes.
	 *
	 * @return bool
	 *   True for a JPEG, a PNG with no alpha or tRNS, an indexed image whose palette is all
	 *   opaque, and a lossy WebP without an alpha chunk. Anything else is not known to be opaque.
	 */
	private static function opaque(GdImage $src): bool
	{
		$b = (string) $src->bytes;
		if (str_starts_with($b, "\xff\xd8")) {
			return true;
		}
		if (!$src->trueColor) {
			return $src->transparent === -1 && array_sum(array_column($src->colors, 3)) === 0;
		}
		if (str_starts_with($b, "\x89PNG")) {
			return !in_array(ord($b[25] ?? "\0"), [4, 6], true) && !str_contains($b, 'tRNS');
		}
		return str_starts_with($b, 'RIFF') &&
			substr($b, 8, 4) === 'WEBP' &&
			substr($b, 12, 4) === 'VP8 ';
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
		if ($image->bytes === null && !$image->painted) {
			self::refuse('imagerotate', 'the image is a blank canvas with nothing painted on it');
			return false;
		}
		if ($background_color < 0) {
			return false;
		}
		$turn = fmod(fmod($angle, 360) + 360, 360);
		$out = $image->fork();
		if ($turn === 0.0) {
			return $out;
		}
		if (!$image->trueColor) {
			if (!$image->colorsKnown) {
				return self::refuse(
					'imagerotate',
					'the palette came from a quantizer whose result is not known without the pixels',
				);
			}
			self::noteHeaderPalette($image, 'imagerotate');
			// libgd turns the source into true color first and reads the background from its table
			$entry = $image->colors[$background_color] ?? [0, 0, 0, 0];
			$background_color = self::pack(...$entry);
			$out->trueColor = true;
			$out->colors = [];
			$out->open = [];
			$out->headerPalette = false;
			$out->transparent = -1;
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
			'width' => $out->width,
			'height' => $out->height,
		];
		return $out;
	}

	// #endregion

	// #region copy, fill, flip and filters

	/**
	 * Queues a copy of a source rectangle onto the target at an offset, like imagecopy().
	 *
	 * @param GdImage $dst_image
	 *   The target.
	 * @param GdImage $src_image
	 *   The source.
	 * @param int $dst_x
	 *   The target x offset.
	 * @param int $dst_y
	 *   The target y offset.
	 * @param int $src_x
	 *   The source rectangle x.
	 * @param int $src_y
	 *   The source rectangle y.
	 * @param int $src_width
	 *   The rectangle width.
	 * @param int $src_height
	 *   The rectangle height.
	 *
	 * @return bool
	 *   True when queued; false and a recorded degradation for a copy the queue cannot express.
	 */
	public static function imagecopy(
		GdImage $dst_image,
		GdImage $src_image,
		int $dst_x,
		int $dst_y,
		int $src_x,
		int $src_y,
		int $src_width,
		int $src_height,
	): bool {
		return self::place(
			'imagecopy',
			$dst_image,
			$src_image,
			[$dst_x, $dst_y, $src_x, $src_y, $src_width, $src_height],
			null,
		);
	}

	/**
	 * Queues a blend of a source rectangle onto the target, like imagecopymerge().
	 *
	 * @param GdImage $dst_image
	 *   The target.
	 * @param GdImage $src_image
	 *   The source.
	 * @param int $dst_x
	 *   The target x offset.
	 * @param int $dst_y
	 *   The target y offset.
	 * @param int $src_x
	 *   The source rectangle x.
	 * @param int $src_y
	 *   The source rectangle y.
	 * @param int $src_width
	 *   The rectangle width.
	 * @param int $src_height
	 *   The rectangle height.
	 * @param int $pct
	 *   How much of the source shows, 0 to 100.
	 *
	 * @return bool
	 *   True when queued; false and a recorded degradation for a copy the queue cannot express.
	 */
	public static function imagecopymerge(
		GdImage $dst_image,
		GdImage $src_image,
		int $dst_x,
		int $dst_y,
		int $src_x,
		int $src_y,
		int $src_width,
		int $src_height,
		int $pct,
	): bool {
		return self::place(
			'imagecopymerge',
			$dst_image,
			$src_image,
			[$dst_x, $dst_y, $src_x, $src_y, $src_width, $src_height],
			$pct,
		);
	}

	/**
	 * Queues a copy or merge of a rectangle at an offset.
	 *
	 * @param string $fn
	 *   The gd function, for the degradation.
	 * @param GdImage $dst
	 *   The target.
	 * @param GdImage $src
	 *   The source.
	 * @param int[] $r
	 *   Dst x, dst y, src x, src y, width, height.
	 * @param int|null $pct
	 *   The merge percentage, or null for a plain copy.
	 *
	 * @return bool
	 *   True when queued.
	 */
	private static function place(string $fn, GdImage $dst, GdImage $src, array $r, ?int $pct): bool
	{
		[$dx, $dy, $sx, $sy, $w, $h] = $r;
		if ($pct !== null && $pct <= 0) {
			return true;
		}
		if ($w <= 0 || $h <= 0) {
			return true;
		}
		if (!$dst->trueColor) {
			return self::refuse($fn, 'the target is an indexed image, which gd would quantize');
		}
		if ($sx < 0 || $sy < 0 || $sx + $w > $src->width || $sy + $h > $src->height) {
			return self::refuse($fn, 'the source rectangle leaves the source image');
		}
		$layer = [
			'source' => $src->bytes === null ? null : base64_encode($src->bytes),
			'canvas' =>
				$src->bytes === null
					? ['width' => $src->baseWidth, 'height' => $src->baseHeight]
					: null,
			'ops' => $src->ops,
		];
		$dst->ops[] = self::copyOp($layer, $dx, $dy, $sx, $sy, $w, $h, $pct, $dst->blend, $src);
		$dst->painted = true;
		return true;
	}

	/**
	 * Builds a host `copy` operation.
	 *
	 * @param array<string, mixed> $layer
	 *   The source handle's content: source, canvas and ops.
	 * @param int $dx
	 *   The target x.
	 * @param int $dy
	 *   The target y.
	 * @param int $sx
	 *   The source x.
	 * @param int $sy
	 *   The source y.
	 * @param int $w
	 *   The width.
	 * @param int $h
	 *   The height.
	 * @param int|null $merge
	 *   The merge percentage, or null.
	 * @param bool $blend
	 *   The target's alpha blending flag.
	 * @param GdImage $src
	 *   The source handle, whose transparent color gd skips.
	 *
	 * @return array<string, mixed>
	 *   The operation.
	 */
	private static function copyOp(
		array $layer,
		int $dx,
		int $dy,
		int $sx,
		int $sy,
		int $w,
		int $h,
		?int $merge,
		bool $blend,
		GdImage $src,
	): array {
		$op = [
			'op' => 'copy',
			'src' => $layer,
			'dx' => $dx,
			'dy' => $dy,
			'sx' => $sx,
			'sy' => $sy,
			'width' => $w,
			'height' => $h,
			'blend' => $blend,
		];
		if ($merge !== null) {
			$op['merge'] = $merge;
		}
		if ($src->transparent >= 0) {
			$op['skip'] = $src->trueColor ? $src->transparent : -2;
		}
		return $op;
	}

	/**
	 * Queues a fill of a canvas nothing has painted, like imagefill().
	 *
	 * A flood fill reads pixels to find its region. Only a blank canvas has a region PHP can name,
	 * which is all of it, so any other image is refused.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param int $x
	 *   The seed x.
	 * @param int $y
	 *   The seed y.
	 * @param int $color
	 *   The packed color.
	 *
	 * @return bool
	 *   True when queued or when the seed is outside the image; false for a fill the queue cannot express.
	 */
	public static function imagefill(GdImage $image, int $x, int $y, int $color): bool
	{
		if (!$image->trueColor || !self::uniform($image)) {
			return self::refuse(
				'imagefill',
				'a flood fill has to read pixels, and only a blank true color canvas has a region PHP knows',
			);
		}
		if ($color < 0) {
			return self::refuse('imagefill', 'a styled or tiled fill needs the drawing state');
		}
		if ($x < 0 || $y < 0 || $x >= $image->width || $y >= $image->height) {
			return true;
		}
		$image->ops[] = ['op' => 'fill', 'x' => $x, 'y' => $y, 'color' => $color];
		return true;
	}

	/**
	 * Whether every pixel of the image is one color, which only a blank canvas can promise.
	 *
	 * @param GdImage $image
	 *   The image.
	 *
	 * @return bool
	 *   True for a canvas with no source that only fills and flips have touched.
	 */
	private static function uniform(GdImage $image): bool
	{
		if ($image->bytes !== null || $image->painted) {
			return false;
		}
		foreach ($image->ops as $op) {
			if ($op['op'] !== 'fill' && $op['op'] !== 'flip') {
				return false;
			}
		}
		return true;
	}

	/**
	 * Queues a mirror, like imageflip().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param int $mode
	 *   IMG_FLIP_HORIZONTAL, IMG_FLIP_VERTICAL or IMG_FLIP_BOTH.
	 *
	 * @return bool
	 *   Always true.
	 *
	 * @throws ValueError
	 *   For a mode that is none of the three.
	 */
	public static function imageflip(GdImage $image, int $mode): bool
	{
		if ($mode < 1 || $mode > 3) {
			throw new ValueError('imageflip(): Argument #2 ($mode) must be a valid flip mode');
		}
		$image->ops[] = ['op' => 'flip', 'mode' => $mode];
		return true;
	}

	/**
	 * Queues one of the eight filters a CMS calls, like imagefilter().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param int $filter
	 *   An IMG_FILTER_* constant: negate, grayscale, brightness, contrast, edge detect, emboss, mean
	 *   removal or smooth.
	 * @param int|float|bool ...$args
	 *   The level for brightness, contrast and smooth.
	 *
	 * @return bool
	 *   True when queued; false and a recorded degradation for any other filter or an indexed image.
	 *
	 * @throws \ArgumentCountError
	 *   When brightness, contrast or smooth is called without its level.
	 */
	public static function imagefilter(GdImage $image, int $filter, int|float|bool ...$args): bool
	{
		$served = [0, 1, 2, 3, 5, 6, 9, 10];
		if (!in_array($filter, $served, true)) {
			return self::refuse(
				'imagefilter',
				'only negate, grayscale, brightness, contrast, edge detect, emboss, mean removal and smooth are queued',
			);
		}
		$needsLevel = in_array($filter, [2, 3, 10], true);
		if ($needsLevel && $args === []) {
			throw new \ArgumentCountError(
				sprintf('imagefilter() expects exactly 3 arguments, 2 given'),
			);
		}
		if (!$image->trueColor) {
			return self::refuse(
				'imagefilter',
				'the image is indexed, and gd would filter into its palette',
			);
		}
		$op = ['op' => 'filter', 'type' => $filter];
		if ($needsLevel) {
			$op['arg1'] = (int) $args[0];
		}
		$image->ops[] = $op;
		$image->painted = true;
		return true;
	}

	// #endregion

	// #region color table and flags

	/**
	 * Allocates a color with an alpha, like imagecolorallocatealpha().
	 *
	 * A true color image has no table, so the color is the packed integer itself. An indexed image
	 * gets a new table entry, and false when the table already holds 256.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param int $red
	 *   The channel value, 0 to 255.
	 * @param int $green
	 *   The channel value, 0 to 255.
	 * @param int $blue
	 *   The channel value, 0 to 255.
	 * @param int $alpha
	 *   The alpha, 0 opaque to 127 transparent.
	 *
	 * @return int|false
	 *   The color.
	 *
	 * @throws ValueError
	 *   For a channel outside its range.
	 */
	public static function imagecolorallocatealpha(
		GdImage $image,
		int $red,
		int $green,
		int $blue,
		int $alpha,
	): int|false {
		return self::allocate('imagecolorallocatealpha', $image, $red, $green, $blue, $alpha);
	}

	/**
	 * Allocates an opaque color, like imagecolorallocate().
	 *
	 * Drupal core calls it from the CreateNew operation. It is imagecolorallocatealpha() with an
	 * alpha of 0, on the same color table.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param int $red
	 *   The channel value, 0 to 255.
	 * @param int $green
	 *   The channel value, 0 to 255.
	 * @param int $blue
	 *   The channel value, 0 to 255.
	 *
	 * @return int|false
	 *   The color.
	 *
	 * @throws ValueError
	 *   For a channel outside its range.
	 */
	public static function imagecolorallocate(
		GdImage $image,
		int $red,
		int $green,
		int $blue,
	): int|false {
		return self::allocate('imagecolorallocate', $image, $red, $green, $blue, 0);
	}

	/**
	 * The shared body of the two allocators, with the caller's name for its errors.
	 */
	private static function allocate(
		string $fn,
		GdImage $image,
		int $red,
		int $green,
		int $blue,
		int $alpha,
	): int|false {
		self::checkColor($fn, $red, $green, $blue, $alpha);
		if ($image->trueColor) {
			return self::pack($red, $green, $blue, $alpha);
		}
		if (!$image->colorsKnown) {
			return self::refuse(
				$fn,
				'the palette came from a quantizer whose result is not known without the pixels',
			);
		}
		self::noteHeaderPalette($image, $fn);
		if ($image->open !== []) {
			$index = array_shift($image->open);
			$image->colors[$index] = [$red, $green, $blue, $alpha];
			return $index;
		}
		if (count($image->colors) >= 256) {
			return false;
		}
		$image->colors[] = [$red, $green, $blue, $alpha];
		return count($image->colors) - 1;
	}

	/**
	 * Reads a color back, like imagecolorsforindex().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param int $color
	 *   A packed color on a true color image, or a table index.
	 *
	 * @return array<string, int>|false
	 *   Keys red, green, blue and alpha.
	 *
	 * @throws ValueError
	 *   For an index outside the table.
	 */
	public static function imagecolorsforindex(GdImage $image, int $color): array|false
	{
		if ($image->trueColor) {
			return [
				'red' => ($color >> 16) & 255,
				'green' => ($color >> 8) & 255,
				'blue' => $color & 255,
				'alpha' => ($color >> 24) & 127,
			];
		}
		if (!$image->colorsKnown) {
			return self::refuse(
				'imagecolorsforindex',
				'the palette came from a quantizer whose result is not known without the pixels',
			);
		}
		self::noteHeaderPalette($image, 'imagecolorsforindex');
		if ($color < 0 || $color >= count($image->colors)) {
			throw new ValueError('imagecolorsforindex(): Argument #2 ($color) is out of range');
		}
		[$r, $g, $b, $a] = $image->colors[$color];
		return ['red' => $r, 'green' => $g, 'blue' => $b, 'alpha' => $a];
	}

	/**
	 * Counts the table, like imagecolorstotal().
	 *
	 * @param GdImage $image
	 *   The image.
	 *
	 * @return int|false
	 *   0 for a true color image.
	 */
	public static function imagecolorstotal(GdImage $image): int|false
	{
		if ($image->trueColor) {
			return 0;
		}
		if (!$image->colorsKnown) {
			return self::refuse(
				'imagecolorstotal',
				'the palette came from a quantizer whose result is not known without the pixels',
			);
		}
		self::noteHeaderPalette($image, 'imagecolorstotal');
		return count($image->colors);
	}

	/**
	 * Trims a GIF palette to the highest index a pixel uses, as gd's reader does.
	 *
	 * The count is bookkeeping that the pixel indices decide, so it is read once, on first use, from
	 * the compressed index stream; no pixel value is kept or returned.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param string $fn
	 *   The gd function, for the degradation when the stream cannot be read.
	 */
	private static function noteHeaderPalette(GdImage $image, string $fn): void
	{
		if (!$image->headerPalette || $image->bytes === null) {
			return;
		}
		$image->headerPalette = false;
		$used = self::gifUsedIndices($image->bytes);
		if ($used === null) {
			Degradation::record(
				$fn,
				'the GIF index stream could not be read, so the palette count is the header table size',
				'untested',
			);
			return;
		}
		$max = $used[count($used) - 1];
		$image->colors = array_slice($image->colors, 0, $max + 1);
		$image->open = array_values(array_diff(range(0, $max), $used));
	}

	/**
	 * Finds the palette indices the first GIF frame uses, by walking its LZW codes.
	 *
	 * @param string $bytes
	 *   The GIF.
	 *
	 * @return int[]|null
	 *   The used indices in order, or null when the file cannot be walked.
	 */
	private static function gifUsedIndices(string $bytes): ?array
	{
		$length = strlen($bytes);
		$packed = ord($bytes[10] ?? "\0");
		$at = 13 + ($packed & 0x80 ? 3 * (1 << (($packed & 7) + 1)) : 0);
		while ($at < $length && ord($bytes[$at]) !== 0x2c) {
			if (ord($bytes[$at]) !== 0x21) {
				return null;
			}
			$at += 2;
			while ($at < $length && ($n = ord($bytes[$at])) !== 0) {
				$at += $n + 1;
			}
			$at++;
		}
		if ($at + 10 >= $length) {
			return null;
		}
		$flags = ord($bytes[$at + 9]);
		$at += 10 + ($flags & 0x80 ? 3 * (1 << (($flags & 7) + 1)) : 0);
		$min = ord($bytes[$at++]);
		$data = '';
		while ($at < $length && ($n = ord($bytes[$at])) !== 0) {
			$data .= substr($bytes, $at + 1, $n);
			$at += $n + 1;
		}
		$clear = 1 << $min;
		$size = $min + 1;
		$next = $clear + 2;
		$first = [];
		$sets = [];
		$all = str_repeat("\0", 32);
		$bit = static function (int $c): string {
			$set = str_repeat("\0", 32);
			$set[$c >> 3] = chr(1 << ($c & 7));
			return $set;
		};
		$prev = null;
		$bits = 0;
		$have = 0;
		$pos = 0;
		$total = strlen($data);
		while (true) {
			while ($have < $size && $pos < $total) {
				$bits |= ord($data[$pos++]) << $have;
				$have += 8;
			}
			if ($have < $size) {
				break;
			}
			$code = $bits & ((1 << $size) - 1);
			$bits >>= $size;
			$have -= $size;
			if ($code === $clear) {
				$size = $min + 1;
				$next = $clear + 2;
				$prev = null;
				continue;
			}
			if ($code === $clear + 1) {
				break;
			}
			if ($prev === null) {
				$all |= $bit($code & 255);
				$prev = $code;
				continue;
			}
			$prevFirst = $first[$prev] ?? $prev;
			$prevSet = $sets[$prev] ?? $bit($prev & 255);
			if ($code < $next) {
				$f = $first[$code] ?? $code;
				$m = $sets[$code] ?? $bit($code & 255);
			} else {
				$f = $prevFirst;
				$m = $prevSet | $bit($f & 255);
			}
			$all |= $m;
			if ($next < 4096) {
				$first[$next] = $prevFirst;
				$sets[$next] = $prevSet | $bit($f & 255);
				$next++;
				if ($next === 1 << $size && $size < 12) {
					$size++;
				}
			}
			$prev = $code;
		}
		$used = [];
		for ($i = 0; $i < 256; $i++) {
			if (ord($all[$i >> 3]) & (1 << ($i & 7))) {
				$used[] = $i;
			}
		}
		return $used === [] ? null : $used;
	}

	/**
	 * Finds an exact color, like imagecolorexactalpha().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param int $red
	 *   The channel value, 0 to 255.
	 * @param int $green
	 *   The channel value, 0 to 255.
	 * @param int $blue
	 *   The channel value, 0 to 255.
	 * @param int $alpha
	 *   The alpha, 0 opaque to 127 transparent.
	 *
	 * @return int|false
	 *   The packed color on a true color image; the first matching index or -1 on an indexed one.
	 *
	 * @throws ValueError
	 *   For a channel outside its range.
	 */
	public static function imagecolorexactalpha(
		GdImage $image,
		int $red,
		int $green,
		int $blue,
		int $alpha,
	): int|false {
		self::checkColor('imagecolorexactalpha', $red, $green, $blue, $alpha);
		if ($image->trueColor) {
			return self::pack($red, $green, $blue, $alpha);
		}
		if (!$image->colorsKnown) {
			return self::refuse(
				'imagecolorexactalpha',
				'the palette came from a quantizer whose result is not known without the pixels',
			);
		}
		self::noteHeaderPalette($image, 'imagecolorexactalpha');
		foreach ($image->colors as $index => $entry) {
			if ($entry === [$red, $green, $blue, $alpha] && !in_array($index, $image->open, true)) {
				return $index;
			}
		}
		return -1;
	}

	/**
	 * Reads or sets the transparent color, like imagecolortransparent().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param int|null $color
	 *   The color to make transparent; null reads.
	 *
	 * @return int
	 *   The transparent color, or -1 for none.
	 */
	public static function imagecolortransparent(GdImage $image, ?int $color = null): int
	{
		if ($color === null) {
			return $image->transparent;
		}
		if ($color < 0) {
			return $color;
		}
		if (!$image->trueColor) {
			self::noteHeaderPalette($image, 'imagecolortransparent');
			if ($color >= count($image->colors)) {
				return $color;
			}
			if ($image->transparent >= 0 && isset($image->colors[$image->transparent])) {
				$image->colors[$image->transparent][3] = 0;
			}
			$image->colors[$color][3] = 127;
		}
		$image->transparent = $color;
		return $color;
	}

	/**
	 * Whether the image is true color, like imageistruecolor().
	 *
	 * @param GdImage $image
	 *   The image.
	 *
	 * @return bool
	 *   False for an image gd holds as indexed color.
	 */
	public static function imageistruecolor(GdImage $image): bool
	{
		return $image->trueColor;
	}

	/**
	 * Marks the image as indexed, like imagetruecolortopalette().
	 *
	 * The queue never quantizes: the host writes the container the caller asks for. An image that
	 * is already indexed stays as it is. A true color image becomes indexed with an unknown table,
	 * so reading the table back is refused.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param bool $dither
	 *   Accepted and ignored.
	 * @param int $num_colors
	 *   The table size wanted.
	 *
	 * @return bool
	 *   Always true.
	 *
	 * @throws ValueError
	 *   For a table size below 1.
	 */
	public static function imagetruecolortopalette(
		GdImage $image,
		bool $dither,
		int $num_colors,
	): bool {
		if ($num_colors <= 0) {
			throw new ValueError(
				'imagetruecolortopalette(): Argument #3 ($num_colors) must be greater than 0 and less than 2147483647',
			);
		}
		if ($image->trueColor) {
			$image->trueColor = false;
			$image->colors = [];
			$image->colorsKnown = false;
			Degradation::record(
				'imagetruecolortopalette',
				'the palette is not built here; the host writes the container the caller asks for',
				'untested',
			);
		}
		return true;
	}

	/**
	 * Stores the interlace flag, like imageinterlace().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param bool|null $enable
	 *   The new setting; null reads it.
	 *
	 * @return bool
	 *   The setting.
	 */
	public static function imageinterlace(GdImage $image, ?bool $enable = null): bool
	{
		if ($enable !== null) {
			$image->interlace = $enable;
		}
		return $image->interlace;
	}

	/**
	 * Stores the antialias flag, like imageantialias().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param bool $enable
	 *   Whether antialiasing is on.
	 *
	 * @return bool
	 *   Always true, as gd answers for an indexed image too.
	 */
	public static function imageantialias(GdImage $image, bool $enable): bool
	{
		$image->antialias = $enable;
		return true;
	}

	/**
	 * Reports the formats the shim reads and writes, like imagetypes().
	 *
	 * @return int
	 *   A bit mask of IMG_GIF, IMG_JPG, IMG_PNG, IMG_WEBP and IMG_AVIF.
	 */
	public static function imagetypes(): int
	{
		return self::CONSTANTS['IMG_GIF'] |
			self::CONSTANTS['IMG_JPG'] |
			self::CONSTANTS['IMG_PNG'] |
			self::CONSTANTS['IMG_WEBP'] |
			self::CONSTANTS['IMG_AVIF'];
	}

	/**
	 * Reports what the shim supports, like gd_info().
	 *
	 * Only a format whose functions the differential passes is listed as supported.
	 *
	 * @return array<string, bool|string>
	 *   The keys gd_info() returns.
	 */
	// phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	public static function gd_info(): array
	{
		return [
			'GD Version' => 'drupflare tinyimg shim',
			'FreeType Support' => false,
			'GIF Read Support' => true,
			'GIF Create Support' => true,
			'JPEG Support' => true,
			'PNG Support' => true,
			'WBMP Support' => false,
			'XPM Support' => false,
			'XBM Support' => false,
			'WebP Support' => true,
			'BMP Support' => false,
			'AVIF Support' => true,
			'TGA Read Support' => false,
			'JIS-mapped Japanese Font Support' => false,
		];
	}

	/**
	 * Packs a color the way gd holds a true color pixel.
	 *
	 * @param int $red
	 *   The channel value, 0 to 255.
	 * @param int $green
	 *   The channel value, 0 to 255.
	 * @param int $blue
	 *   The channel value, 0 to 255.
	 * @param int $alpha
	 *   The alpha, 0 to 127.
	 *
	 * @return int
	 *   The integer.
	 */
	private static function pack(int $red, int $green, int $blue, int $alpha): int
	{
		return ($alpha << 24) | ($red << 16) | ($green << 8) | $blue;
	}

	/**
	 * Throws the ValueError PHP gives a channel outside its range.
	 *
	 * @param string $fn
	 *   The gd function.
	 * @param int $red
	 *   The red channel.
	 * @param int $green
	 *   The green channel.
	 * @param int $blue
	 *   The blue channel.
	 * @param int $alpha
	 *   The alpha channel.
	 *
	 * @throws ValueError
	 *   For a channel outside 0 to 255, or alpha outside 0 to 127.
	 */
	private static function checkColor(
		string $fn,
		int $red,
		int $green,
		int $blue,
		int $alpha,
	): void {
		foreach (
			[2 => ['$red', $red], 3 => ['$green', $green], 4 => ['$blue', $blue]]
			as $n => [$name, $v]
		) {
			if ($v < 0 || $v > 255) {
				throw new ValueError(
					sprintf(
						'%s(): Argument #%d (%s) must be between 0 and 255 (inclusive)',
						$fn,
						$n,
						$name,
					),
				);
			}
		}
		if ($alpha < 0 || $alpha > 127) {
			throw new ValueError(
				sprintf('%s(): Argument #5 ($alpha) must be between 0 and 127 (inclusive)', $fn),
			);
		}
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
	 * Writes the image as an AVIF, like imageavif().
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param resource|string|null $file
	 *   A path, a stream, or null to print the bytes.
	 * @param int $quality
	 *   From 0 to 100; -1 is 52, which is what gd writes for its default.
	 * @param int $speed
	 *   Accepted and ignored.
	 *
	 * @return bool
	 *   True when the bytes were written.
	 */
	public static function imageavif(
		GdImage $image,
		$file = null,
		int $quality = -1,
		int $speed = -1,
	): bool {
		return self::write(
			$image,
			'avif',
			$file,
			$quality < 0 ? 52 : min(100, $quality),
			'imageavif',
		);
	}

	/**
	 * Writes the image as a GD2 file, like imagegd2().
	 *
	 * Only a true color handle is written. A palette GD2 needs the pixel indices, and the host holds
	 * decoded pixels, so an indexed handle answers false with a degradation. Drupal, WordPress and
	 * Joomla never write GD2; Drupal's toolkit probes for this function name.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param string|null $file
	 *   A path, or null to print the bytes.
	 * @param int $chunk_size
	 *   The chunk edge in pixels; -1 is 128, and the host clamps the rest to 64 through 4096.
	 * @param int $mode
	 *   IMG_GD2_RAW, or IMG_GD2_COMPRESSED; any other value writes compressed, as libgd does.
	 *
	 * @return bool
	 *   True when the bytes were written.
	 */
	public static function imagegd2(
		GdImage $image,
		?string $file = null,
		int $chunk_size = 128,
		int $mode = 1,
	): bool {
		if ($chunk_size < -2147483648 || $chunk_size > 2147483647) {
			return false;
		}
		if (!$image->trueColor) {
			return self::refuse(
				'imagegd2',
				'a palette GD2 needs the pixel indices, which the host does not keep; only true color handles are written',
			);
		}
		return self::write($image, 'gd2', $file, 0, 'imagegd2', [
			'chunk' => $chunk_size === -1 ? 128 : $chunk_size,
			'mode' => $mode,
		]);
	}

	/**
	 * Encodes through the host and writes the bytes where the caller asked.
	 *
	 * @param GdImage $image
	 *   The image.
	 * @param string $format
	 *   One of jpeg, png, webp, gif, avif or gd2.
	 * @param resource|string|null $file
	 *   A path, a stream, or null to print the bytes.
	 * @param int $quality
	 *   The quality or compression level.
	 * @param string $fn
	 *   The gd function, for the degradation.
	 * @param array<string, int> $gd2
	 *   The chunk size and mode of a GD2 write.
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
		array $gd2 = [],
	): bool {
		$bytes = self::render($image, $format, $quality, $fn, $gd2);
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
	 *   One of jpeg, png, webp, gif, avif or gd2.
	 * @param int $quality
	 *   The quality or compression level.
	 * @param string $fn
	 *   The gd function, for the degradation.
	 * @param array<string, int> $gd2
	 *   The chunk size and mode of a GD2 write.
	 *
	 * @return string|null
	 *   The encoded bytes, or null with a degradation recorded.
	 */
	private static function render(
		GdImage $image,
		string $format,
		int $quality,
		string $fn,
		array $gd2 = [],
	): ?string {
		if (!self::available()) {
			self::refuse($fn, 'this deployment has no image host to run the queued operations');
			return null;
		}
		$request = [
			'source' => $image->bytes === null ? null : base64_encode($image->bytes),
			'canvas' =>
				$image->bytes === null
					? ['width' => $image->baseWidth, 'height' => $image->baseHeight]
					: null,
			'ops' => $image->ops,
			'format' => $format,
			'quality' => $quality,
			'progressive' => $image->interlace,
		];
		if ($format === 'gif' && $image->trueColor) {
			// libgd keeps no alpha in a GIF made from true color, only its transparent color
			$request['transparent'] = $image->transparent >= 0 ? $image->transparent : null;
		}
		if ($format === 'gd2') {
			$request['gd2'] = $gd2;
			$request['transparent'] = $image->transparent;
		}
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
		if ($image->interlace && ($format === 'png' || $format === 'gif')) {
			Degradation::record(
				'gd interlace',
				'the host writes PNG and GIF without interlacing; only a JPEG is written progressive',
				'blocked',
			);
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
	 * @return false
	 *   Always false.
	 */
	private static function refuse(string $fn, string $why): false
	{
		Degradation::record($fn, $why, 'blocked');
		return false;
	}
}
