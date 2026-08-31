<?php

namespace Drupal\drupflare\Health\Tripwire;

use Drupal\drupflare\Health\Finding;
use Drupal\drupflare\Health\TripwireInterface;

/**
 * Pre-flights a LIKE or GLOB pattern against the host's 50-byte ceiling.
 *
 * MEASURED by bisection: 50 bytes succeed, 51 fails with
 * "LIKE or GLOB pattern too complex: SQLITE_ERROR", against SQLite's own default of 50,000 -- the
 * runtime lowers it by three orders of magnitude.
 *
 * The check runs on the TRANSLATED pattern, which is the detail that matters: bracket-quoting a
 * metacharacter expands it threefold, so 20 asterisks become a 60-byte GLOB pattern and fail where
 * the input looked safe. It binds plain LIKE too, which the driver cannot intercept -- a Views
 * "contains" filter on a long search string fails inside the engine.
 */
final class SqlLikeOver50 implements TripwireInterface
{
	/**
	 * The measured ceiling, in bytes, on the translated pattern.
	 */
	const MAX_PATTERN_BYTES = 50;

	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'sql.like_over_50';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$pattern = $observation['translated_pattern'] ?? null;
		if (!is_string($pattern)) {
			return null;
		}
		$bytes = strlen($pattern);
		if ($bytes <= self::MAX_PATTERN_BYTES) {
			return null;
		}
		return new Finding(
			$this->code(),
			Finding::ERROR,
			(string) ($observation['table'] ?? 'statement'),
			"translated pattern is {$bytes} bytes against a measured ceiling of " .
				self::MAX_PATTERN_BYTES,
		);
	}
}
