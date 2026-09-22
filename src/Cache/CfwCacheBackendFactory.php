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
 *
 * ## And it is where an in-memory bin is selected, rather than a service of its own
 *
 * The obvious route is core's: register `cache.backend.cfw_memory` and point
 * `$settings['cache']['bins']` at it. Measured, that breaks every existing site. A service name is
 * resolved out of the COMPILED container, the pack ships that container prebuilt, and
 * `getContainerCacheKey()` does not move when `assets/driver.json` does -- so the settings route
 * names a service the container has never heard of and the boot throws
 * `ServiceNotFoundException`. Driven on a fresh site: the arm with the bin selected rendered
 * nothing at all, 0 rows and 0 bytes, against 8 rows on the control.
 *
 * This class is already in the container and its BODY is remounted from the driver pack on every
 * boot, so deciding here takes effect on the next request of every site rather than after a
 * container rebuild. The same reasoning is why the null `page` bin is a services.yml written by the
 * host rather than a settings entry.
 */
class CfwCacheBackendFactory extends DatabaseBackendFactory
{
	/**
	 * {@inheritdoc}
	 */
	public function get($bin)
	{
		if (in_array($bin, $this->memoryBins(), true)) {
			return new CfwMemoryBackend($bin, $this->checksumProvider, $this->memoryMaxItems());
		}
		return new CfwCacheBackend(
			$this->connection,
			$this->checksumProvider,
			$bin,
			$this->serializer,
			$this->time,
			$this->getMaxRowsForBin($bin),
		);
	}

	/**
	 * Bins the interpreter keeps in memory, from settings.
	 *
	 * Empty by default. What the lever buys is measured -- a real re-render after a tag
	 * invalidation charges 9 rows on the shipping pack, 6 of them the dynamic_page_cache bin --
	 * and what it costs back is a rebuild after every interpreter drop, which is a property of a
	 * site's own traffic rather than of the code.
	 *
	 * @return string[]
	 *   Bare bin names, without the cache_ prefix core adds.
	 */
	private function memoryBins(): array
	{
		$bins = $this->settings->get('drupflare')['memory_cache_bins'] ?? [];
		return is_array($bins) ? array_values(array_filter($bins, 'is_string')) : [];
	}

	/**
	 * Entries one in-memory bin may hold; see CfwMemoryBackend::DEFAULT_MAX_ITEMS.
	 */
	private function memoryMaxItems(): int
	{
		$configured = $this->settings->get('drupflare')['memory_cache_max_items'] ?? null;
		$n = is_numeric($configured) ? (int) $configured : 0;
		return $n > 0 ? $n : CfwMemoryBackend::DEFAULT_MAX_ITEMS;
	}
}
