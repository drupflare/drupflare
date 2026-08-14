<?php

namespace Drupal\drupflare\Health\Tripwire;

use Drupal\drupflare\Health\Finding;
use Drupal\drupflare\Health\TripwireInterface;

/**
 * Asserts that a render reports which cache tier produced it.
 *
 * This is RULE 3 of the measurement rules turned into a runtime assertion. A dozen figures across
 * three rounds of this project were page_cache hits wearing a render's label, and the check that
 * would have caught every one of them is reading x-drupal-cache before believing the number. A
 * response with no cache header is one nobody can attribute after the fact.
 */
final class CacheHeaderMissing implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'cache.header_missing';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		if (empty($observation['is_render'])) {
			return null;
		}
		$header = $observation['drupal_cache_header'] ?? null;
		if (is_string($header) && $header !== '') {
			return null;
		}
		return new Finding(
			$this->code(),
			Finding::WARN,
			(string) ($observation['path'] ?? '?'),
			'a render reported no x-drupal-cache, so its tier is unattributable',
		);
	}
}
