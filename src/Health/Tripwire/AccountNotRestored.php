<?php

namespace Drupal\drupflare\Health\Tripwire;

use Drupal\drupflare\Health\Finding;
use Drupal\drupflare\Health\TripwireInterface;

/**
 * Asserts the account switcher stack is empty when the request ends.
 *
 * The other half of the uid-1 disclosure. A switch that is not popped leaves the NEXT request
 * running as whoever the last one switched to, and on a persistent interpreter that is not a
 * theoretical hazard -- it is the mechanism that put admin HTML into the anonymous page cache.
 * A non-empty stack is therefore critical rather than a warning.
 */
final class AccountNotRestored implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'account.not_restored';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$depth = (int) ($observation['switcher_depth'] ?? 0);
		$uid = $observation['uid'] ?? null;
		if ($depth <= 0) {
			return null;
		}
		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			'account_switcher',
			"stack depth {$depth} at request end, currently uid " . (string) ($uid ?? '?'),
		);
	}
}
