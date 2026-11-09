<?php

declare(strict_types=1);

namespace Drupal\drupflare\Image;

use Drupal;

/**
 * Decides when `system.image.toolkit` is moved to `cfw_images`.
 *
 * Core ships `toolkit: gd`, and `ImageToolkitManager::getDefaultToolkitId()` keeps the configured
 * toolkit whenever its `isAvailable()` is true. The gd shim defines `imagegd2`, which is what
 * `GDToolkit::isAvailable()` tests, so a site that still names the core default would switch to
 * `GDToolkit`, and every style would then be built at save time through a park. Only the
 * untouched core default is replaced; any other value, `gd` chosen on purpose included, is a
 * choice made after install and stays.
 */
final class ToolkitDefault
{
	public const ID = 'cfw_images';

	/**
	 * The toolkit id to store, or null to leave the configuration alone.
	 */
	public static function replacement(mixed $configured): ?string
	{
		return $configured === 'gd' ? self::ID : null;
	}

	/**
	 * Applies {@see self::replacement()} to the live `system.image` config.
	 *
	 * @return bool
	 *   True when the value was changed.
	 */
	public static function apply(): bool
	{
		if (!Drupal::hasContainer()) {
			return false;
		}
		$config = Drupal::configFactory()->getEditable('system.image');
		$id = self::replacement($config->get('toolkit'));
		if ($id === null) {
			return false;
		}
		$config->set('toolkit', $id)->save();
		return true;
	}
}
