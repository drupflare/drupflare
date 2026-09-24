<?php

declare(strict_types=1);

namespace Drupal\drupflare\Image;

/**
 * An image style's effect chain as the one delivery-time transform the Worker renders.
 *
 * The toolkit writes no derivative, so a style reaches a visitor only through the Worker's delivery
 * path. A chain this cannot express as one transform answers null and keeps its stock URL.
 */
final class DeliveryTransform
{
	/**
	 * The transform an effect chain produces, or null.
	 *
	 * @param array<int, array{id: string, data: array<string, mixed>}> $effects
	 *   The style's effects in order, each as its plugin id and configuration data.
	 *
	 * @return array<string, int|string>|null
	 *   width, height, fit and format, or null when the chain has no single-transform form.
	 */
	public static function fromEffects(array $effects): ?array
	{
		$transform = [];
		$geometry = false;
		foreach ($effects as $effect) {
			$data = $effect['data'] ?? [];
			switch ($effect['id'] ?? '') {
				case 'image_scale':
				case 'image_scale_and_crop':
				case 'image_resize':
					// two resizes compose into something one transform cannot state
					if ($geometry) {
						return null;
					}
					$geometry = true;
					foreach (['width', 'height'] as $side) {
						$value = (int) ($data[$side] ?? 0);
						if ($value > 0) {
							$transform[$side] = $value;
						}
					}
					if (!isset($transform['width']) && !isset($transform['height'])) {
						return null;
					}
					$transform['fit'] = match ($effect['id']) {
						'image_scale' => empty($data['upscale']) ? 'scale-down' : 'inside',
						'image_scale_and_crop' => 'cover',
						default => 'fill',
					};
					break;

				case 'image_convert':
					$extension = strtolower((string) ($data['extension'] ?? ''));
					if ($extension === '') {
						return null;
					}
					$transform['format'] = $extension === 'jpg' ? 'jpeg' : $extension;
					break;

				case 'image_convert_avif':
					$transform['format'] = 'avif';
					break;

				default:
					return null;
			}
		}
		return $transform === [] ? null : $transform;
	}

	/**
	 * The source path of a derivative path, undoing the extension a converting style appends.
	 *
	 * @param string $path
	 *   The derivative path under the style directory.
	 * @param callable(string): string $derivativeExtension
	 *   The style's own `getDerivativeExtension()`.
	 */
	public static function sourcePath(string $path, callable $derivativeExtension): string
	{
		$extension = pathinfo($path, PATHINFO_EXTENSION);
		if ($extension === '') {
			return $path;
		}
		$base = substr($path, 0, -strlen($extension) - 1);
		$original = pathinfo($base, PATHINFO_EXTENSION);
		if ($base === '' || $original === '' || $original === $extension) {
			return $path;
		}
		return $derivativeExtension($original) === $extension ? $base : $path;
	}
}
