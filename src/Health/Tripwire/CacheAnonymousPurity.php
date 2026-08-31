<?php

namespace Drupal\drupflare\Health\Tripwire;

use Drupal\drupflare\Health\Finding;
use Drupal\drupflare\Health\TripwireInterface;

/**
 * Refuses to let a response that is not anonymous be written to the anonymous page cache.
 *
 * THIS SHIPPED, and it was an information-disclosure bug. A node save switched
 * Drupal::currentUser() to uid 1 and never switched back; because the interpreter persists
 * between requests, the alarm chain then rendered the front page AS uid 1, that 90,038-byte admin
 * HTML was stored in cfw_page -- which is the ANONYMOUS cache -- and it was served to the next
 * anonymous visitor.
 *
 * The check is three separate conditions rather than one: any of them alone is
 * enough to make the entry unsafe, and naming which one failed is what makes the ledger useful.
 */
final class CacheAnonymousPurity implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'cache.anonymous_purity';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		if (empty($observation['writing_page_cache'])) {
			return null;
		}
		$reasons = [];
		if (!empty($observation['uid'])) {
			$reasons[] = 'uid ' . (int) $observation['uid'];
		}
		if (!empty($observation['set_cookie'])) {
			$reasons[] = 'Set-Cookie present';
		}
		$contexts = $observation['cache_contexts'] ?? [];
		if (is_array($contexts) && in_array('session', $contexts, true)) {
			$reasons[] = 'session cache context';
		}
		if ($reasons === []) {
			return null;
		}
		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			(string) ($observation['path'] ?? '?'),
			'refusing an anonymous cache write: ' . implode(', ', $reasons),
		);
	}
}
