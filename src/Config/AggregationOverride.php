<?php

declare(strict_types=1);

namespace Drupal\drupflare\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Keeps Drupal's own CSS and JS aggregation off, because it cannot work in this runtime.
 *
 * Aggregation reads the source files with the file functions of the interpreter, and the stylesheets
 * and scripts a browser is sent are not in the interpreter's filesystem: the asset layer serves them
 * and the pack carries code. The aggregate route therefore answers a licence header alone, and a page
 * that asks for it loses its whole theme. Measured on a migrated production site, 2026-10-16: the
 * administration theme's aggregate was 3,327 bytes where the theme is hundreds of kilobytes, and the
 * script aggregates were 73 and 71 bytes (`; ;`).
 *
 * The worker does its own aggregation at the edge from files it published, under `ASSET_AGGREGATES`,
 * and it needs Drupal to hand it the individual tags. A site that was carried over from a host where
 * aggregation was on is the usual way to arrive here, since it is the production default for a
 * deployed Drupal, and a config import would bring the setting back, so the override is a
 * constant of the platform rather than a value written once.
 */
final class AggregationOverride implements ConfigFactoryOverrideInterface
{
	/**
	 * The config object that holds the setting.
	 */
	public const CONFIG = 'system.performance';

	/**
	 * {@inheritdoc}
	 */
	public function loadOverrides($names)
	{
		if (!in_array(self::CONFIG, (array) $names, true)) {
			return [];
		}
		return [
			self::CONFIG => [
				'css' => ['preprocess' => false],
				'js' => ['preprocess' => false],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCacheSuffix()
	{
		return 'drupflare_aggregation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION)
	{
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCacheableMetadata($name)
	{
		// a constant of this deployment, so it adds no context and no tag
		return new CacheableMetadata();
	}
}
