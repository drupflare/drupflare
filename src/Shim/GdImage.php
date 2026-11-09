<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

/**
 * An image handle for {@see Gd}: encoded source bytes plus the operations queued on them.
 *
 * Nothing is decoded in PHP. The operations run in the host when the image is written out, so the
 * handle only tracks the geometry the caller can read back through `imagesx()` and `imagesy()`.
 * Without a real ext-gd the global `GdImage` name is an alias of this class from the first handle.
 */
final class GdImage
{
	/**
	 * The width of the blank canvas a handle without bytes started as, which its queue runs on.
	 */
	public int $baseWidth = 0;

	/**
	 * The height of the blank canvas a handle without bytes started as.
	 */
	public int $baseHeight = 0;

	/**
	 * Alpha blending, as `imagealphablending()` sets it.
	 */
	public bool $blend = true;

	/**
	 * Whether `imagesavealpha()` asked for the alpha channel to be written.
	 */
	public bool $saveAlpha = false;

	/**
	 * A blank canvas has no source until a copy paints it.
	 */
	public bool $painted = false;

	/**
	 * False for an image gd would hold as indexed color, such as a GIF or a palette PNG.
	 */
	public bool $trueColor = true;

	/**
	 * The palette of an indexed image, as `[red, green, blue, alpha]` per index, alpha 0 to 127.
	 *
	 * @var array<int, array{int, int, int, int}>
	 */
	public array $colors = [];

	/**
	 * Whether `$colors` is still the untrimmed GIF header table.
	 *
	 * Gd trims such a table to the highest index a pixel uses, which only the index stream can say.
	 */
	public bool $headerPalette = false;

	/**
	 * Palette indices no pixel uses, which gd marks open and hands out to the next allocation.
	 *
	 * @var int[]
	 */
	public array $open = [];

	/**
	 * Whether `$colors` holds the palette gd would hold.
	 *
	 * False after `imagetruecolortopalette()`, whose quantizer result cannot be known without the
	 * pixels.
	 */
	public bool $colorsKnown = true;

	/**
	 * What `imagecolortransparent()` reports.
	 *
	 * A palette index, or a packed color on a true color image; -1 for none.
	 */
	public int $transparent = -1;

	/**
	 * Whether `imageinterlace()` asked for an interlaced or progressive stream.
	 */
	public bool $interlace = false;

	/**
	 * Whether `imageantialias()` was switched on; no operation the queue carries reads it.
	 */
	public bool $antialias = false;

	/**
	 * Wraps encoded bytes and the operations queued on them.
	 *
	 * @param string|null $bytes
	 *   The encoded source; null for a blank canvas.
	 * @param int $width
	 *   The current width in pixels.
	 * @param int $height
	 *   The current height in pixels.
	 * @param array<int, array<string, mixed>> $ops
	 *   Operations for the host, in order: crop, resize, rotate, flip, fill, copy, filter.
	 */
	public function __construct(
		public ?string $bytes,
		public int $width,
		public int $height,
		public array $ops = [],
	) {
		self::declareAs('GdImage');
	}

	/**
	 * Gives this class a global name, so `instanceof GdImage` and a `\GdImage` type hint accept a handle.
	 *
	 * Neither check autoloads, so the runtime's lazy alias is not enough: the name has to exist
	 * once a handle does. A real ext-gd already owns the name, and nothing is declared then.
	 *
	 * @param string $name
	 *   The global class name to declare.
	 */
	public static function declareAs(string $name): void
	{
		if (!class_exists($name, false)) {
			class_alias(self::class, $name);
		}
	}

	/**
	 * A copy that shares the source and carries the same queue.
	 */
	public function fork(): self
	{
		$copy = clone $this;
		$copy->painted = $this->painted;
		return $copy;
	}
}
