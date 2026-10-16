<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

/**
 * Where the Redis stand-in keeps its keys.
 *
 * A key is a string, a string value and an optional expiry. Everything else a real Redis holds
 * (hashes, lists, sets, scripts) is outside what the stand-in answers for.
 */
interface RedisStoreInterface
{
	/**
	 * The expiry stored for a key that has none, which is the largest value a 32-bit column holds.
	 *
	 * @var int
	 */
	public const NO_EXPIRY = 2147483647;

	/**
	 * One live key, or NULL when it is absent or expired.
	 *
	 * @param int $db
	 *   The Redis database number the key lives in.
	 * @param string $key
	 *   The key.
	 * @param int $now
	 *   The time a row must outlive to count as live.
	 *
	 * @return array{v: string, expire: int|null}|null
	 *   The value and expiry of the key, or NULL.
	 */
	public function read(int $db, string $key, int $now): ?array;

	/**
	 * Stores a key, replacing any earlier one.
	 *
	 * @param int $db
	 *   The database number.
	 * @param string $key
	 *   The key.
	 * @param string $value
	 *   The value.
	 * @param int|null $expire
	 *   Unix time the key stops existing, or NULL for never.
	 */
	public function write(int $db, string $key, string $value, ?int $expire): void;

	/**
	 * Removes a key.
	 *
	 * @param int $db
	 *   The database index.
	 * @param string $key
	 *   The key.
	 *
	 * @return bool
	 *   Whether a row was there to remove.
	 */
	public function delete(int $db, string $key): bool;

	/**
	 * Every live key in a database.
	 *
	 * @param int $db
	 *   The database index.
	 * @param int $now
	 *   The current unix time.
	 *
	 * @return array<string, array{v: string, expire: int|null}>
	 *   Rows keyed by Redis key.
	 */
	public function all(int $db, int $now): array;

	/**
	 * Drops the rows that have expired, so a table of short-lived keys does not grow without end.
	 *
	 * @param int $db
	 *   The database index.
	 * @param int $now
	 *   The current unix time.
	 */
	public function purge(int $db, int $now): void;
}
