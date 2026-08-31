<?php

namespace Drupal\drupflare\Health;

use Drupal\drupflare\Health\Tripwire\ConfigDrift;

/**
 * The checks that run on every Durable Object boot, before the first request is served.
 *
 * This is /probe generalised and made mandatory. Each check is a precondition that has been
 * observed false at least once, and serving with any of them false produces plausible wrong output
 * rather than an error -- which is exactly the class of failure this project keeps paying for.
 *
 * It runs BEFORE serving: a site that cannot pass these should quarantine rather than
 * answer, because a 503 with Retry-After is a better answer than a page rendered against a quarter
 * of its own database.
 */
final class BootSelfTest
{
	/**
	 * The SQLite feature floor Drupal 11.4.5 requires.
	 *
	 * Drupal gates installation on 3.45. The obvious probe -- concat() -- only proves 3.44 and would
	 * FAIL the gate, which is why the version is established by feature probe and reported as a
	 * floor rather than a version.
	 */
	const SQLITE_FLOOR = '3.45';

	/**
	 * Evaluates every boot precondition.
	 *
	 * @param array $observation
	 *   What the host gathered before handing control to PHP.
	 *
	 * @return Finding[]
	 *   One finding per failed precondition; empty when the object may serve.
	 */
	public static function run(array $observation): array
	{
		$found = [];

		if (empty($observation['bridge_installed'])) {
			$found[] = new Finding(
				'boot.bridge_missing',
				Finding::CRITICAL,
				'bridge',
				'the host bridge is absent, so no capability can answer',
			);
		}

		$missing = $observation['missing_capabilities'] ?? [];
		if (is_array($missing) && $missing !== []) {
			$found[] = new Finding(
				'boot.capability_missing',
				Finding::ERROR,
				'capabilities',
				'absent: ' . implode(', ', array_map('strval', $missing)),
			);
		}

		$version = $observation['sqlite_version'] ?? null;
		if (is_string($version) && version_compare($version, self::SQLITE_FLOOR, '<')) {
			$found[] = new Finding(
				'boot.sqlite_too_old',
				Finding::CRITICAL,
				'sqlite',
				"floor {$version} is below the required " . self::SQLITE_FLOOR,
			);
		}

		$chunk = $observation['migrate_chunk'] ?? null;
		$chunks = $observation['migrate_chunks'] ?? null;
		if (
			$chunk !== null &&
			$chunks !== null &&
			(int) $chunks > 0 &&
			(int) $chunk < (int) $chunks
		) {
			$found[] = new Finding(
				'boot.migrate_incomplete',
				Finding::CRITICAL,
				'migration',
				'chunk ' . (int) $chunk . ' of ' . (int) $chunks,
			);
		}

		if (($observation['updb_phase'] ?? null) === 'halted') {
			$found[] = new Finding(
				'boot.updb_halted',
				Finding::ERROR,
				'updb',
				'a halted run is holding the alarm chain',
			);
		}

		$pack = $observation['pack_generation'] ?? null;
		$db = $observation['db_generation'] ?? null;
		if (is_string($pack) && is_string($db) && $pack !== '' && $db !== '' && $pack !== $db) {
			$found[] = new Finding(
				'boot.generation_mismatch',
				Finding::CRITICAL,
				'pack',
				"pack {$pack} against database {$db}",
			);
		}

		$drift = (new ConfigDrift())->check($observation);
		if ($drift !== null) {
			$found[] = $drift;
		}

		return $found;
	}

	/**
	 * Whether the object may serve at all.
	 *
	 * @param Finding[] $findings
	 *   The result of run().
	 *
	 * @return bool
	 *   FALSE when any finding is critical.
	 */
	public static function mayServe(array $findings): bool
	{
		foreach ($findings as $finding) {
			if ($finding->severity >= Finding::CRITICAL) {
				return false;
			}
		}
		return true;
	}
}
