<?php

namespace Drupal\drupflare\Health\Tripwire;

use Drupal\drupflare\Health\Finding;
use Drupal\drupflare\Health\TripwireInterface;

/**
 * Pre-flights a statement against the host's bound-parameter ceiling.
 *
 * MEASURED, not assumed: 100 bound parameters succeed and 101 throws
 * "too many SQL variables: SQLITE_ERROR". SQLite's own compile-time default is 32,766, so this is
 * the runtime lowering it by two orders of magnitude.
 *
 * It is not a corner case, it is the cache write path. DatabaseBackend::setMultiple() chunks at 100
 * ROWS, a cache bin has 7 columns, and core's sqlite Upsert emits one multi-row statement -- so a
 * cold cache_discovery write is 574 placeholders and any cache flush reaches it. The driver
 * re-batches, and this tripwire is what notices a path the re-batching does not cover, notably a
 * large IN () built by Condition.
 */
final class SqlParamsOver100 implements TripwireInterface
{
	/**
	 * The measured ceiling.
	 */
	const MAX_PLACEHOLDERS = 100;

	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'sql.params_over_100';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$count = (int) ($observation['param_count'] ?? 0);
		if ($count <= self::MAX_PLACEHOLDERS) {
			return null;
		}
		return new Finding(
			$this->code(),
			Finding::ERROR,
			(string) ($observation['table'] ?? 'statement'),
			"{$count} bound parameters against a measured ceiling of " . self::MAX_PLACEHOLDERS,
		);
	}
}
