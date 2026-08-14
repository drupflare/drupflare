<?php

namespace Drupal\drupflare\Health;

use Drupal\drupflare\Health\Tripwire\AccountNotRestored;
use Drupal\drupflare\Health\Tripwire\CacheAnonymousPurity;
use Drupal\drupflare\Health\Tripwire\CacheHeaderMissing;
use Drupal\drupflare\Health\Tripwire\ConfigDrift;
use Drupal\drupflare\Health\Tripwire\DbTxnLeaked;
use Drupal\drupflare\Health\Tripwire\SqlLikeOver50;
use Drupal\drupflare\Health\Tripwire\SqlParamsOver100;

/**
 * The PHP-side tripwires, and the one place they are enumerated.
 *
 * The host half in src/ops/supervisor.ts holds the checks PHP structurally cannot make: a JS throw
 * out of a wasm import, its own isolate being killed, or wasm linear memory rising. This half holds
 * the ones that need Drupal's own state, which the host cannot see.
 */
final class TripwireRegistry
{
	/**
	 * The tripwires, in evaluation order.
	 *
	 * @var TripwireInterface[]
	 */
	private array $wires;

	/**
	 * Constructs the registry with the default set.
	 */
	public function __construct()
	{
		$this->wires = [
			new CacheAnonymousPurity(),
			new CacheHeaderMissing(),
			new DbTxnLeaked(),
			new AccountNotRestored(),
			new SqlParamsOver100(),
			new SqlLikeOver50(),
			new ConfigDrift(),
		];
	}

	/**
	 * Every registered tripwire.
	 *
	 * @return TripwireInterface[]
	 *   The wires, in evaluation order.
	 */
	public function all(): array
	{
		return $this->wires;
	}

	/**
	 * Runs every tripwire over one observation.
	 *
	 * @param array $observation
	 *   Scalars the caller already had.
	 *
	 * @return Finding[]
	 *   Every finding, in wire order.
	 */
	public function run(array $observation): array
	{
		$found = [];
		foreach ($this->wires as $wire) {
			$finding = $wire->check($observation);
			if ($finding !== null) {
				$found[] = $finding;
			}
		}
		return $found;
	}
}
