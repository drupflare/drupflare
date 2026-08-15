<?php

declare(strict_types=1);

namespace Drupal\drupflare\Cache;

use Drupal\Core\Cache\DatabaseBackend;

/**
 * Drops the two cache-bin indexes nothing on this runtime reads.
 *
 * Under Durable Object billing an index is not a read optimisation with a small write cost -- every
 * index on a table is another row charged per insert, and rows written is the meter that binds the
 * regeneration ceiling. Core declares two on every bin, `expire` and `created`, on top of the `cid`
 * primary key. Measured in workerd: 10 rows cost **40 charged rows with both indexes and 20 without
 * exactly 2.00x**, and across the shipped schema 67.3% of all charged rows are index maintenance.
 *
 * @see CfwCacheBackendFactory which installs this
 */
class CfwCacheBackend extends DatabaseBackend
{
	/**
	 * Bins whose indexes are load-bearing and must be kept.
	 *
	 * `cache_data` only, because it is the only bin the host garbage-collects. If a future pass
	 * teaches `gcPass()` to cap another bin, its name belongs here in the same commit -- an
	 * unindexed cap query is a full scan of the bin on every alarm.
	 */
	public const INDEXED_BINS = ['cache_data'];

	/**
	 * {@inheritdoc}
	 */
	public function schemaDefinition()
	{
		$schema = parent::schemaDefinition();
		if (in_array($this->bin, self::INDEXED_BINS, true)) {
			return $schema;
		}
		unset($schema['indexes']['expire'], $schema['indexes']['created']);
		if (($schema['indexes'] ?? []) === []) {
			unset($schema['indexes']);
		}
		return $schema;
	}
}
