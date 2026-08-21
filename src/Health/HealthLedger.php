<?php

namespace Drupal\drupflare\Health;

use Drupal\drupflare\Host;

/**
 * The PHP writer for cfw_health.
 *
 * One table, two writers: this class and src/ops/supervisor.ts. The table is owned by the host,
 * which is deliberate -- the ledger has to survive the interpreter that produced the entry, and a
 * PHP shutdown handler cannot see an isolate being killed.
 *
 * Writes go over the capability bridge rather than through Drupal's database layer, for the reason
 * the whole health design turns on: a repair path must not depend on the subsystem it repairs. A
 * finding about a leaked transaction cannot be written through the transaction layer that leaked.
 */
final class HealthLedger
{
	/**
	 * Records one finding.
	 *
	 * @param Finding $finding
	 *   What was noticed.
	 * @param string $action
	 *   The ladder rung acted at, if any.
	 * @param string $outcome
	 *   What the action achieved, if anything.
	 * @param int $attempt
	 *   Which attempt this was, for the circuit breaker.
	 *
	 * @return bool
	 *   TRUE when the host accepted the entry.
	 */
	public static function record(
		Finding $finding,
		string $action = '',
		string $outcome = '',
		int $attempt = 0,
	): bool {
		if (!Host::has('cfwHealth')) {
			return false;
		}
		$reply = Host::call(
			'cfwHealth',
			$finding->toArray() + [
				'action' => $action,
				'outcome' => $outcome,
				'attempt' => $attempt,
			],
		);
		return ($reply['ok'] ?? false) === true;
	}

	/**
	 * Records every finding in a list.
	 *
	 * @param Finding[] $findings
	 *   The findings.
	 *
	 * @return int
	 *   How many the host accepted.
	 */
	public static function recordAll(array $findings): int
	{
		$written = 0;
		foreach ($findings as $finding) {
			if (self::record($finding)) {
				$written++;
			}
		}
		return $written;
	}
}
