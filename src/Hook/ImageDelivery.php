<?php

declare(strict_types=1);

namespace Drupal\drupflare\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\drupflare\Image\DeliveryTransform;
use Drupal\drupflare\Plugin\ImageToolkit\CfwImageToolkit;
use Drupal\image\Entity\ImageStyle;

/**
 * Points a public image style's URL at the Worker's delivery path.
 *
 * `CfwImageToolkit::save()` copies the original to the derivative path, so without this every style
 * served the full-size source. Private files keep their stock URL and its access check.
 */
class ImageDelivery
{
	/**
	 * Rewrites a public style URI to the delivery URL for its source and transform.
	 *
	 * @param string $uri
	 *   The file URI, replaced in place when the style maps to one transform.
	 */
	#[Hook('file_url_alter')]
	public function fileUrlAlter(string &$uri): void
	{
		$prefix = 'public://styles/';
		if (!str_starts_with($uri, $prefix)) {
			return;
		}
		$parts = explode('/', substr($uri, strlen($prefix)), 3);
		if (count($parts) !== 3 || $parts[1] !== 'public' || $parts[2] === '') {
			return;
		}
		$style = ImageStyle::load($parts[0]);
		if ($style === null) {
			return;
		}
		$effects = [];
		foreach ($style->getEffects() as $effect) {
			$effects[] = [
				'id' => $effect->getPluginId(),
				'data' => $effect->getConfiguration()['data'] ?? [],
			];
		}
		$transform = DeliveryTransform::fromEffects($effects);
		if ($transform === null) {
			return;
		}
		$source =
			'public://' .
			DeliveryTransform::sourcePath(
				$parts[2],
				fn(string $extension): string => $style->getDerivativeExtension($extension),
			);
		$url = CfwImageToolkit::deliveryUrl($source, $transform);
		if ($url !== null && $url !== '') {
			$uri = $url;
		}
	}
}
