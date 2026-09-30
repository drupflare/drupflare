<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

/**
 * An image handle for {@see Gd}: encoded source bytes plus the operations queued on them.
 *
 * Nothing is decoded in PHP. The operations run in the host when the image is written out, so the
 * handle only tracks the geometry the caller can read back through `imagesx()` and `imagesy()`.
 * The runtime aliases the global `GdImage` name to this class.
 */
final class GdImage
{
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
	 * Wraps encoded bytes and the operations queued on them.
	 *
	 * @param string|null $bytes
	 *   The encoded source; null for a blank canvas.
	 * @param int $width
	 *   The current width in pixels.
	 * @param int $height
	 *   The current height in pixels.
	 * @param array<int, array<string, mixed>> $ops
	 *   Operations for the host, in order: crop, resize, rotate.
	 */
	public function __construct(
		public ?string $bytes,
		public int $width,
		public int $height,
		public array $ops = [],
	) {}

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
