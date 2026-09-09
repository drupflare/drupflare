<?php

declare(strict_types=1);

namespace Drupal\drupflare\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Points system.mail at cfw_mail, and only while this module is installed.
 *
 * The php_mail plugin cannot run in this runtime: there is no sendmail binary and PHP here has no
 * sockets of its own. So the mailer is a platform substitution like the database driver, not a site
 * preference, and it must survive a config import that would otherwise revert a site into a mailer
 * that drops every message silently.
 *
 * THE SETTINGS OVERRIDE THAT USED TO DO THIS WAS A 500 ON EVERY SITE. settings.php assigned
 * system.mail:interface.default = cfw_mail unconditionally, cfw_mail is a plugin of THIS module,
 * and the shipped core.extension does not list this module -- so MailManager threw
 * PluginNotFoundException for an interface it could not resolve and /user/password answered 500
 * rather than failing to send. Measured on a provisioned site: 'The cfw_mail plugin does not exist.
 * Valid plugin IDs are: php_mail, symfony_mailer, test_mail_collector'.
 *
 * A config override service cannot make that mistake. It is registered by this module, so it exists
 * exactly when the plugin does; it beats a config import, which is what the settings override was
 * for; and it needs no install hook to have fired, which is what a site enabled through the host
 * route cannot rely on.
 */
final class MailInterfaceOverride implements ConfigFactoryOverrideInterface
{
	/**
	 * The plugin id this module provides.
	 */
	public const PLUGIN = 'cfw_mail';

	/**
	 * {@inheritdoc}
	 */
	public function loadOverrides($names)
	{
		if (!in_array('system.mail', (array) $names, true)) {
			return [];
		}
		return ['system.mail' => ['interface' => ['default' => self::PLUGIN]]];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCacheSuffix()
	{
		return 'drupflare_mail';
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
		// the override is a constant of this deployment rather than of any request, so it adds no
		// context and no tag; a page carrying it is as cacheable as one that is not
		return new CacheableMetadata();
	}
}
