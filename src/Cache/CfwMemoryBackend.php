<?php

declare(strict_types=1);

namespace Drupal\drupflare\Cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;

/**
 * A cache bin that lives in the interpreter rather than in the tenant's SQLite.
 *
 * `pib_run` performs no `php_request_startup`/`shutdown` cycle, so class statics survive from one
 * Worker invocation to the next for the life of the incarnation. A normal PHP SAPI throws its
 * memory away between requests, which is why core's `MemoryBackend` is a test double there; here it
 * is a real tier, sitting between the request and a bin whose rows are charged.
 *
 * Measured on the shipping pack: a real re-render after a tag invalidation charges **9 rows, of
 * which 6 are `cache_dynamic_page_cache`** -- two thirds of the cost of the operation the free
 * plan's regeneration ceiling is computed from, in one bin.
 *
 * Core's `MemoryBackend` learns about an invalidation by being called, which works on one process
 * and fails on a read replica: a lane receives an invalidation as a replayed `cachetags` statement,
 * never as a PHP call, so an in-memory entry there would outlive its own invalidation forever. This
 * stores the checksum with the item and asks `cache_tags.invalidator.checksum` on every read, the
 * same test `DatabaseBackend` applies -- and that provider reads the `cachetags` table, which IS
 * replicated. A lane rejects its own stale entries with nothing told to it.
 *
 * The isolate is 128 MiB and the worst measured workload peaks at 92.69 MiB, so an unbounded bin
 * would spend the headroom the interpreter needs. `USE_ZEND_ALLOC=0` means PHP returns nothing
 * between requests, so the footprint is a high-water mark until the whole interpreter is dropped.
 * The bound is an item count rather than a byte count because measuring bytes means serialising,
 * which is the cost moving off SQLite removes.
 */
class CfwMemoryBackend implements CacheBackendInterface
{
	/**
	 * Entries per bin, and the reason the number is a count.
	 *
	 * 64 pages of `dynamic_page_cache` is a few MiB against ~35 MiB of measured headroom.
	 * `recycleIfOversized()` at 112 MiB is the backstop underneath it, so the failure mode of a
	 * wrong bound is one interpreter drop rather than an isolate reset.
	 */
	public const DEFAULT_MAX_ITEMS = 64;

	/**
	 * The store, per bin, oldest first.
	 *
	 * Static so it outlives one invocation, which is the entire mechanism. Keyed by bin rather than
	 * held per instance because Drupal builds a backend per bin per container, and a rebuilt
	 * container must not drop what the previous one cached.
	 */
	private static array $store = [];

	public function __construct(
		private readonly string $bin,
		private readonly CacheTagsChecksumInterface $checksumProvider,
		private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
	) {
		self::$store[$this->bin] ??= [];
	}

	/**
	 * How many entries each bin is holding.
	 *
	 * Not on any report: reading it from the host means entering the interpreter, which a stats read
	 * must not pay for. What it is for is the bound -- `tests/health-suite.php` drives the eviction
	 * against it, and an eviction policy nothing can observe is one nobody can check.
	 */
	public static function counts(): array
	{
		$out = [];
		foreach (self::$store as $bin => $items) {
			$out[$bin] = count($items);
		}
		return $out;
	}

	/**
	 * Drops everything every bin holds.
	 *
	 * Production never needs it: dropping the interpreter destroys the statics, which is the only
	 * way this tier is ever emptied. It exists so one test case cannot inherit another's store.
	 */
	public static function reset(): void
	{
		self::$store = [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get($cid, $allow_invalid = false)
	{
		$item = self::$store[$this->bin][$cid] ?? null;
		if ($item === null) {
			return false;
		}
		return $this->prepare($item, $allow_invalid);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMultiple(&$cids, $allow_invalid = false)
	{
		$found = [];
		foreach ($cids as $cid) {
			$item = $this->get($cid, $allow_invalid);
			if ($item !== false) {
				$found[$cid] = $item;
			}
		}
		$cids = array_values(array_diff($cids, array_keys($found)));
		return $found;
	}

	/**
	 * {@inheritdoc}
	 */
	public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = [])
	{
		$tags = array_unique(array_map('strval', $tags));
		sort($tags);
		// unset first, so a re-set moves the entry to the END of the insertion order and the
		// eviction below drops what has genuinely been idle longest rather than what was stored
		// longest ago
		unset(self::$store[$this->bin][$cid]);
		self::$store[$this->bin][$cid] = (object) [
			'cid' => $cid,
			'data' => $data,
			'created' => round(microtime(true), 3),
			'expire' => $expire,
			'tags' => $tags,
			'checksum' => $this->checksumProvider->getCurrentChecksum($tags),
			'valid' => true,
		];
		$this->evict();
	}

	/**
	 * {@inheritdoc}
	 */
	public function setMultiple(array $items)
	{
		foreach ($items as $cid => $item) {
			$this->set(
				$cid,
				$item['data'],
				$item['expire'] ?? Cache::PERMANENT,
				$item['tags'] ?? [],
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($cid)
	{
		unset(self::$store[$this->bin][$cid]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteMultiple(array $cids)
	{
		foreach ($cids as $cid) {
			$this->delete($cid);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteAll()
	{
		self::$store[$this->bin] = [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function invalidate($cid)
	{
		if (isset(self::$store[$this->bin][$cid])) {
			self::$store[$this->bin][$cid]->valid = false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function invalidateMultiple(array $cids)
	{
		foreach ($cids as $cid) {
			$this->invalidate($cid);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function invalidateAll()
	{
		foreach (self::$store[$this->bin] as $item) {
			$item->valid = false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function garbageCollection()
	{
		$now = round(microtime(true), 3);
		foreach (self::$store[$this->bin] as $cid => $item) {
			if ($item->expire !== Cache::PERMANENT && $item->expire < $now) {
				unset(self::$store[$this->bin][$cid]);
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function removeBin()
	{
		unset(self::$store[$this->bin]);
	}

	/**
	 * Whether an item may be returned, and the checksum is the half a lane depends on.
	 *
	 * An expired or explicitly invalidated entry is refused the way core's backends refuse one. A
	 * TAG-invalidated entry is refused by comparing the checksum stored with it against the current
	 * one, which is what makes this correct on an object that was never told about the
	 * invalidation.
	 */
	private function prepare(object $item, bool $allowInvalid)
	{
		$copy = clone $item;
		$now = round(microtime(true), 3);
		if ($copy->expire !== Cache::PERMANENT && $copy->expire < $now) {
			$copy->valid = false;
		}
		if ($copy->valid && !$this->checksumProvider->isValid($copy->checksum, $copy->tags)) {
			$copy->valid = false;
		}
		return $allowInvalid || $copy->valid ? $copy : false;
	}

	/**
	 * Drops the oldest entries once the bin is over its bound.
	 */
	private function evict(): void
	{
		$over = count(self::$store[$this->bin]) - $this->maxItems;
		if ($over <= 0) {
			return;
		}
		foreach (array_slice(array_keys(self::$store[$this->bin]), 0, $over) as $cid) {
			unset(self::$store[$this->bin][$cid]);
		}
	}
}
