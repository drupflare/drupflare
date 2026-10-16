<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use Closure;
use Drupal;
use Exception;

/**
 * The Redis stand-in's keys, in the table core already ships for expiring key/value pairs.
 *
 * `key_value_expire` is part of every Drupal database, so a dump, an export and a backup carry the
 * keys with nothing else to configure, and a site moved off the edge takes them with it. One
 * collection per Redis database number.
 *
 * The row name is the key itself, or a digest when the key is longer than the column or is not
 * valid UTF-8; the real key travels inside the value either way, so listing never has to reverse
 * a digest. A value that is not valid UTF-8 is stored base64 encoded, because the bridge to the
 * database carries text.
 *
 * Core creates the table the first time its own expirable store is used, which a fresh site may not
 * have done, so the first statement that fails makes core create it and runs once more.
 */
final class KeyValueRedisStore implements RedisStoreInterface
{
	/**
	 * Collection name prefix; the database number follows it. `drangler` writes the same names.
	 *
	 * @var string
	 */
	public const COLLECTION_PREFIX = 'cfw_redis.';

	/**
	 * The widest `name` the table holds.
	 *
	 * @var int
	 */
	private const NAME_MAX = 128;

	/**
	 * Whether the table has been ensured to exist.
	 *
	 * @var bool
	 */
	private bool $ensured = false;

	/**
	 * Creates the store.
	 *
	 * @param Closure|null $ensure
	 *   Makes the table exist; NULL has core's expirable store write a row, which creates it. For
	 *   a spec.
	 */
	public function __construct(private readonly ?Closure $ensure = null) {}

	/**
	 * The collection a database number lives in.
	 *
	 * @param int $db
	 *   The database number.
	 *
	 * @return string
	 *   The collection name.
	 */
	public static function collection(int $db): string
	{
		return self::COLLECTION_PREFIX . $db;
	}

	/**
	 * The row name for a key.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return string
	 *   The row name.
	 */
	public static function nameOf(string $key): string
	{
		if (strlen($key) <= self::NAME_MAX && mb_check_encoding($key, 'UTF-8')) {
			return $key;
		}
		return 'sha256:' . hash('sha256', $key);
	}

	/**
	 * The stored form of a key and its value.
	 *
	 * @param string $key
	 *   The key.
	 * @param string $value
	 *   The value.
	 *
	 * @return string
	 *   The stored value.
	 */
	public static function pack(string $key, string $value): string
	{
		$binary = !mb_check_encoding($key . $value, 'UTF-8');
		return serialize(
			$binary
				? ['k' => base64_encode($key), 'v' => base64_encode($value), 'b' => true]
				: ['k' => $key, 'v' => $value],
		);
	}

	/**
	 * A stored row back to its key and value, or NULL for something this class did not write.
	 *
	 * @param string $stored
	 *   The `value` column.
	 *
	 * @return array{k: string, v: string}|null
	 *   The key and value, or NULL when the row is not a stored key.
	 */
	public static function unpack(string $stored): ?array
	{
		$row = @unserialize($stored, ['allowed_classes' => false]);
		if (!is_array($row) || !isset($row['k'], $row['v']) || !is_string($row['k'])) {
			return null;
		}
		if (!is_string($row['v'])) {
			return null;
		}
		if (($row['b'] ?? false) === true) {
			$key = base64_decode($row['k'], true);
			$value = base64_decode($row['v'], true);
			return $key === false || $value === false ? null : ['k' => $key, 'v' => $value];
		}
		return ['k' => $row['k'], 'v' => $row['v']];
	}

	/**
	 * Reads one key, or NULL when it is absent or expired.
	 *
	 * @param int $db
	 *   The database index.
	 * @param string $key
	 *   The key.
	 * @param int $now
	 *   The current unix time.
	 *
	 * @return ?array
	 *   The value and expiry of the key, or NULL.
	 */
	public function read(int $db, string $key, int $now): ?array
	{
		$rows = $this->attempt(
			static fn() => Drupal::database()
				->query(
					'SELECT value, expire FROM {key_value_expire} WHERE collection = :c AND name = :n AND expire > :now',
					[':c' => self::collection($db), ':n' => self::nameOf($key), ':now' => $now],
				)
				->fetchAll(),
		);
		$row = $rows[0] ?? null;
		if ($row === null) {
			return null;
		}
		$unpacked = self::unpack((string) $row->value);
		return $unpacked === null || $unpacked['k'] !== $key
			? null
			: ['v' => $unpacked['v'], 'expire' => self::expiryOf((int) $row->expire)];
	}

	/**
	 * Writes one key with an optional expiry.
	 *
	 * @param int $db
	 *   The database index.
	 * @param string $key
	 *   The key.
	 * @param string $value
	 *   The value, stored as a string.
	 * @param ?int $expire
	 *   Unix seconds the key expires at, or NULL for none.
	 */
	public function write(int $db, string $key, string $value, ?int $expire): void
	{
		$this->delete($db, $key);
		$this->attempt(
			static fn() => Drupal::database()
				->insert('key_value_expire')
				->fields([
					'collection' => self::collection($db),
					'name' => self::nameOf($key),
					'value' => self::pack($key, $value),
					'expire' => $expire ?? self::NO_EXPIRY,
				])
				->execute(),
		);
	}

	/**
	 * Deletes one key.
	 *
	 * @param int $db
	 *   The database index.
	 * @param string $key
	 *   The key.
	 *
	 * @return bool
	 *   TRUE when a row was removed.
	 */
	public function delete(int $db, string $key): bool
	{
		return (int) $this->attempt(
			static fn() => Drupal::database()
				->delete('key_value_expire')
				->condition('collection', self::collection($db))
				->condition('name', self::nameOf($key))
				->execute(),
		) > 0;
	}

	/**
	 * Every live key and value of one database.
	 *
	 * @param int $db
	 *   The database index.
	 * @param int $now
	 *   The current unix time.
	 *
	 * @return array
	 *   Rows keyed by Redis key.
	 */
	public function all(int $db, int $now): array
	{
		$rows = $this->attempt(
			static fn() => Drupal::database()
				->query(
					'SELECT value, expire FROM {key_value_expire} WHERE collection = :c AND expire > :now',
					[':c' => self::collection($db), ':now' => $now],
				)
				->fetchAll(),
		);
		$out = [];
		foreach ($rows as $row) {
			$unpacked = self::unpack((string) $row->value);
			if ($unpacked !== null) {
				$out[$unpacked['k']] = [
					'v' => $unpacked['v'],
					'expire' => self::expiryOf((int) $row->expire),
				];
			}
		}
		return $out;
	}

	/**
	 * Drops the expired keys of the selected database.
	 *
	 * @param int $db
	 *   The database index.
	 * @param int $now
	 *   The current unix time.
	 */
	public function purge(int $db, int $now): void
	{
		$this->attempt(
			static fn() => Drupal::database()
				->delete('key_value_expire')
				->condition('collection', self::collection($db))
				->condition('expire', $now, '<=')
				->execute(),
		);
	}

	/**
	 * Runs one operation, creating the table once if the first attempt fails.
	 *
	 * @param Closure $work
	 *   The operation, which a failed first attempt runs a second time.
	 *
	 * @return mixed
	 *   What the work returned.
	 */
	private function attempt(Closure $work): mixed
	{
		try {
			return $work();
		} catch (Exception $e) {
			if ($this->ensured) {
				throw $e;
			}
			$this->ensured = true;
			// core creates the table on a write and swallows a missing one on a read
			($this->ensure ??
				static fn() => Drupal::keyValueExpirable('cfw_redis')->setWithExpire(
					'ensure',
					true,
					1,
				))();
			return $work();
		}
	}

	/**
	 * A stored expiry as the interface reports it: NULL for the never marker.
	 *
	 * @param int $stored
	 *   The `expire` column.
	 *
	 * @return ?int
	 *   Unix seconds, or NULL.
	 */
	private static function expiryOf(int $stored): ?int
	{
		return $stored >= self::NO_EXPIRY ? null : $stored;
	}
}
