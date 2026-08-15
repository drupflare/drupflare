<?php

declare(strict_types=1);

namespace Drupal\drupflare\Cache;

use Drupal\Core\Cache\DatabaseBackendFactory;

/**
 * Hands out {@link CfwCacheBackend} instead of core's database backend.
 *
 * Core's factory constructs `DatabaseBackend` directly rather than resolving a class, so a subclass
 * of the FACTORY is the seam. Everything else -- the connection, the checksum provider, the
 * serializer, the clock and the per-bin row cap -- is read from the parent, so a site that has
 * configured `database_cache_max_rows` keeps its setting.
 */
class CfwCacheBackendFactory extends DatabaseBackendFactory
{
	/**
	 * {@inheritdoc}
	 */
	public function get($bin)
	{
		return new CfwCacheBackend(
			$this->connection,
			$this->checksumProvider,
			$bin,
			$this->serializer,
			$this->time,
			$this->getMaxRowsForBin($bin),
		);
	}
}
