<?php

namespace Drupal\drupflare\Health\Tripwire;

use Drupal\drupflare\Health\Finding;
use Drupal\drupflare\Health\TripwireInterface;

/**
 * Asserts no transaction is still open when the request ends.
 *
 * The driver buffers writes and replays them inside one transactionSync(), so an open transaction
 * at request end means a buffer that was never replayed -- writes Drupal believes it made and the
 * database never saw. It is also the state in which a repair must never run: DDL beside an open
 * replay dirties sqlite_master and turns every later read speculative, which once wedged the local
 * runtime badly enough that unrelated sites stopped responding.
 */
final class DbTxnLeaked implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'db.txn_leaked';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$depth = (int) ($observation['transaction_depth'] ?? 0);
		if ($depth <= 0) {
			return null;
		}
		return new Finding(
			$this->code(),
			Finding::ERROR,
			'transaction',
			"depth {$depth} at request end; a buffered write was never replayed",
		);
	}
}
