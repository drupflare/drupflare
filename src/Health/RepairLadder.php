<?php

namespace Drupal\drupflare\Health;

/**
 * The escalation ladder, and the rules about what a repair may do.
 *
 * Three constraints bind every rung, each encoding a defect already paid for:
 *
 * - A REPAIR MUST NEVER RUN INSIDE AN OPEN TRANSACTION. DDL beside a replay dirties sqlite_master
 *   and turns every later read in that transaction speculative -- the documented O(W x R) cost,
 *   which once wedged the local runtime hard enough that unrelated sites stopped responding.
 * - A repair that enters PHP goes through the same gate as every other PHP entry, alarm() included.
 *   A nested acquire on that gate is a permanent hang rather than an error, so the gate is checked
 *   rather than taken.
 * - A repair must not be reachable from an untrusted request. No user-triggerable cache flush.
 */
final class RepairLadder
{
	/**
	 * The rungs, lowest first.
	 */
	const RUNGS = ['observe', 'reset', 'reconstruct', 'reconfigure', 'quarantine', 'rollback'];

	/**
	 * Where a finding of this severity starts.
	 *
	 * @param int $severity
	 *   One of the Finding severity ordinals.
	 *
	 * @return string
	 *   A rung name.
	 */
	public static function initialRung(int $severity): string
	{
		if ($severity >= Finding::CRITICAL) {
			return 'quarantine';
		}
		if ($severity >= Finding::ERROR) {
			return 'reset';
		}
		return 'observe';
	}

	/**
	 * One rung up, saturating at the top.
	 *
	 * @param string $rung
	 *   Current rung.
	 *
	 * @return string
	 *   The next rung, or the same one at the top.
	 */
	public static function escalate(string $rung): string
	{
		$at = array_search($rung, self::RUNGS, true);
		if ($at === false) {
			return 'observe';
		}
		return self::RUNGS[min($at + 1, count(self::RUNGS) - 1)];
	}

	/**
	 * One rung down, or NULL at the bottom so the caller can stop tracking the code.
	 *
	 * @param string $rung
	 *   Current rung.
	 *
	 * @return string|null
	 *   The lower rung, or NULL when already at the bottom.
	 */
	public static function decay(string $rung): ?string
	{
		$at = array_search($rung, self::RUNGS, true);
		if ($at === false || $at <= 0) {
			return null;
		}
		return self::RUNGS[$at - 1];
	}

	/**
	 * Whether a repair may run right now.
	 *
	 * Fails CLOSED: an unknown state is treated as unsafe, because the cost of skipping a repair is
	 * a finding that stays in the ledger, and the cost of running one inside a transaction is a
	 * wedged runtime.
	 *
	 * @param array $observation
	 *   Needs transaction_depth and gate_held.
	 *
	 * @return bool
	 *   TRUE only when nothing is open and nothing else holds the gate.
	 */
	public static function maySafelyRepair(array $observation): bool
	{
		if (!array_key_exists('transaction_depth', $observation)) {
			return false;
		}
		if ((int) $observation['transaction_depth'] !== 0) {
			return false;
		}
		return empty($observation['gate_held']);
	}
}
