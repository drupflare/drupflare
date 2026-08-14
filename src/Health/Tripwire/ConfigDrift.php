<?php

namespace Drupal\drupflare\Health\Tripwire;

use Drupal\drupflare\Health\Finding;
use Drupal\drupflare\Health\TripwireInterface;

/**
 * Asserts the three settings this runtime cannot survive having flipped back.
 *
 * Each one is a measured incident rather than a preference:
 *
 * - automated_cron.interval must be 0. AutomatedCron::onTerminate() returns early when PHP_SAPI is
 *   "cli", and PHP_SAPI is "embed" in wasm, so the guard that protects every native site does not
 *   hold here. With it non-zero, drupal_cron() ran inline on the first request, reached for
 *   outbound HTTP, and killed the invocation with an uncatchable Asyncify throw.
 * - system.advisories.enabled must be FALSE. It gates SecurityAdvisoriesFetcher inside
 *   SystemHooks::cron(), which needs outbound HTTPS.
 * - dblog must stay uninstalled. Its watchdog table reached 1,662 rows and 46% of the database.
 *
 * A config import or a module reinstall can undo any of them, and none announces itself.
 */
final class ConfigDrift implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'config.drift';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$drift = [];
		if (
			array_key_exists('automated_cron_interval', $observation) &&
			(int) $observation['automated_cron_interval'] !== 0
		) {
			$drift[] =
				'automated_cron.interval is ' . (int) $observation['automated_cron_interval'];
		}
		if (
			array_key_exists('advisories_enabled', $observation) &&
			!empty($observation['advisories_enabled'])
		) {
			$drift[] = 'system.advisories.enabled is TRUE';
		}
		if (!empty($observation['dblog_installed'])) {
			$drift[] = 'dblog is installed';
		}
		if ($drift === []) {
			return null;
		}
		return new Finding($this->code(), Finding::ERROR, 'config', implode('; ', $drift));
	}
}
