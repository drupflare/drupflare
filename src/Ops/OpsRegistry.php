<?php

namespace Drupal\drupflare\Ops;

/**
 * The operations surface that replaces Drush.
 *
 * Do not ship drush. It is a large dependency whose whole job is to give a shell a way into
 * Drupal, and there is no shell here -- the runtime has no `exec`, no `proc_open` and no sockets.
 * Eight operations get ~95% of the value at a fraction of the pack.
 *
 * Every operation is declared with the two things a caller actually needs to know before invoking
 * it: whether it WRITES, and whether it is expensive enough to need slicing. That is not
 * documentation, it is the gate -- `cr` is 282.9 ms of `drupal_flush_all_caches()` in wasm, which
 * is 28x a free-plan invocation, so it must be driven as the eleven units `UPDB_FLUSH_STEPS`
 * already splits it into rather than called directly.
 *
 * SECURITY: none of these may be reachable from an untrusted request. They live behind the same
 * diagnostic gate as `/php` and `/sql`, and `writes` is what a caller checks before exposing one
 * to anything. A user-triggerable cache flush is a denial-of-service primitive: it makes the next
 * request rebuild `cache_discovery`, which is 82 entries and 574 bound parameters against a
 * measured ceiling of 100.
 */
final class OpsRegistry
{
	/**
	 * The operations, keyed by the short name a Drush user would recognise.
	 *
	 * @return array
	 *   Each value has: label, writes, sliced, and cost, where cost is a measured figure or NULL
	 *   when this project has not measured it.
	 */
	public static function operations(): array
	{
		return [
			'status' => [
				'label' =>
					'Report versions, the migration cursor, the updb phase and the pack generation.',
				'writes' => false,
				'sliced' => false,
				// the only one safe to call on the request path; it is BootSelfTest's observation
				'cost' => 'one read',
			],
			'cr' => [
				'label' => 'Rebuild caches.',
				'writes' => true,
				'sliced' => true,
				// measured: 282.9 ms in wasm, 268.8 ms native, 78.5 MB peak. 28x a free invocation
				'cost' => '282.9 ms wasm; must run as the 11 UPDB_FLUSH_STEPS units',
			],
			'updb' => [
				'label' => 'Run pending database updates.',
				'writes' => true,
				'sliced' => true,
				// already sliced: 28 units and 56 invocations for a 1-update release
				'cost' => '28 units / 56 beats for a 1-update release',
			],
			'cex' => [
				'label' => 'Export configuration.',
				'writes' => false,
				'sliced' => true,
				// a read, but a read of EVERY config object -- 164 on a standard site -- so it is the
				// same shape as sql-dump and not something to do inside one user-facing invocation
				'cost' => '164 config objects on a standard site',
			],
			'cim' => [
				'label' => 'Import configuration.',
				'writes' => true,
				'sliced' => true,
				// a config import can re-enable automated_cron or dblog, which config.drift watches for
				'cost' => null,
			],
			'en' => [
				'label' => 'Install a module.',
				'writes' => true,
				'sliced' => true,
				// measured natively: 1,052.6 ms install + 268.8 ms flush, 8.0 -> 78.5 MB peak
				'cost' => '1,344.7 ms native, 78.5 MB peak; belongs in a Workflow',
			],
			'pmu' => [
				'label' => 'Uninstall a module.',
				'writes' => true,
				'sliced' => true,
				// measured per module: dblog 778.5 ms, update 945.4, announcements_feed 666.4
				'cost' => '666-945 ms per module, measured natively',
			],
			'sql-dump' => [
				'label' => 'Dump the database as replayable SQL.',
				'writes' => false,
				'sliced' => true,
				// measured: 289-294 statements, 1.3-2.1 MB, sha1 reported for integrity
				'cost' => '289-294 statements, 1.3-2.1 MB',
			],
		];
	}

	/**
	 * Whether an operation exists.
	 *
	 * @param string $name
	 *   Short name.
	 *
	 * @return bool
	 *   TRUE when it is declared.
	 */
	public static function has(string $name): bool
	{
		return array_key_exists($name, self::operations());
	}

	/**
	 * Whether an operation mutates anything.
	 *
	 * Fails CLOSED: an unknown operation is treated as writing, so a caller that forgets to check
	 * `has()` first cannot accidentally expose a mutation as a read.
	 *
	 * @param string $name
	 *   Short name.
	 *
	 * @return bool
	 *   TRUE when it writes, or when the name is unknown.
	 */
	public static function writes(string $name): bool
	{
		$op = self::operations()[$name] ?? null;
		if ($op === null) {
			return true;
		}
		return (bool) $op['writes'];
	}

	/**
	 * Whether an operation must be driven in slices rather than called directly.
	 *
	 * Fails CLOSED for the same reason: an unknown operation is assumed expensive, because calling
	 * something unsliced that needed slicing is a killed invocation, and slicing something that did
	 * not need it costs one extra beat.
	 *
	 * @param string $name
	 *   Short name.
	 *
	 * @return bool
	 *   TRUE when it needs slicing, or when the name is unknown.
	 */
	public static function sliced(string $name): bool
	{
		$op = self::operations()[$name] ?? null;
		if ($op === null) {
			return true;
		}
		return (bool) $op['sliced'];
	}

	/**
	 * The only operation safe to run on a user-facing request path.
	 *
	 * @return string[]
	 *   Names that neither write nor need slicing.
	 */
	public static function readOnlyUnsliced(): array
	{
		$safe = [];
		foreach (self::operations() as $name => $op) {
			if (!$op['writes'] && !$op['sliced']) {
				$safe[] = $name;
			}
		}
		return $safe;
	}
}
