<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use Closure;
use Drupal\drupflare\Degradation;

/**
 * The phpredis client a module calls, answered from the site's own database.
 *
 * The phpredis extension is not compiled in and a Worker has no Redis to dial. `drupal/redis` picks
 * its client by class existence, so this class, aliased as `\Redis`, is what its phpredis client
 * constructs and what a module's direct `\Redis` use gets. No socket opens: a key lives in a row of
 * {@see KeyValueRedisStore}, which is part of the database, so a dump, an export and a backup carry
 * it and `drangler preview` can seed it from the source's Redis.
 *
 * What it answers for is the string commands a module uses to keep state: get, set with an expiry
 * or NX/XX, the multi-key forms, delete, exists, expiry and its reads, keys and scan, the counters.
 * A hash, list, set or sorted-set command, a script and a transaction are not here. They throw
 * {@see RedisException} and record a degradation rather than answering with data a real Redis would
 * not have produced.
 */
class Redis
{
	public const REDIS_NOT_FOUND = 0;

	public const REDIS_STRING = 1;

	public const REDIS_SET = 2;

	public const REDIS_LIST = 3;

	public const REDIS_ZSET = 4;

	public const REDIS_HASH = 5;

	public const OPT_SERIALIZER = 1;

	public const OPT_PREFIX = 2;

	public const OPT_READ_TIMEOUT = 3;

	public const OPT_SCAN = 4;

	public const OPT_COMPRESSION = 7;

	public const SERIALIZER_NONE = 0;

	public const SERIALIZER_PHP = 1;

	public const SCAN_NORETRY = 0;

	public const SCAN_RETRY = 1;

	/**
	 * Where keys live; replaced by a spec.
	 *
	 * @var RedisStoreInterface|null
	 */
	private static ?RedisStoreInterface $store = null;

	/**
	 * The clock; replaced by a spec.
	 *
	 * @var Closure|null
	 */
	private static ?Closure $clock = null;

	/**
	 * The selected database index.
	 *
	 * @var int
	 */
	private int $db = 0;

	/**
	 * Whether connect() has been called.
	 *
	 * @var bool
	 */
	private bool $connected = false;

	/**
	 * The options set through setOption(), by constant.
	 *
	 * @var array<int, mixed>
	 */
	private array $options = [];

	/**
	 * The last error a command recorded, if any.
	 *
	 * @var string|null
	 */
	private ?string $lastError = null;

	/**
	 * Replaces the store and the clock.
	 *
	 * @param RedisStoreInterface|null $store
	 *   The store, or NULL for the database table.
	 * @param Closure|null $clock
	 *   Returns unix time, or NULL for the real one.
	 *
	 * @internal
	 *   For a spec.
	 */
	public static function configure(?RedisStoreInterface $store, ?Closure $clock = null): void
	{
		self::$store = $store;
		self::$clock = $clock;
	}

	/**
	 * The store keys live in.
	 *
	 * @return RedisStoreInterface
	 *   The store.
	 */
	private function store(): RedisStoreInterface
	{
		return self::$store ??= new KeyValueRedisStore();
	}

	/**
	 * The current unix time, from the clock a spec may replace.
	 *
	 * @return int
	 *   Unix time in seconds.
	 */
	private function now(): int
	{
		return self::$clock === null ? time() : (int) (self::$clock)();
	}

	/**
	 * The key a command acts on, with the prefix option applied.
	 *
	 * @param string $key
	 *   The key as the caller wrote it.
	 *
	 * @return string
	 *   The key with the prefix.
	 */
	private function keyOf(string $key): string
	{
		$prefix = $this->options[self::OPT_PREFIX] ?? '';
		return (is_string($prefix) ? $prefix : '') . $key;
	}

	/**
	 * Removes the prefix option from a key the store returned.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return string
	 *   The key without the prefix.
	 */
	private function unprefixed(string $key): string
	{
		$prefix = $this->options[self::OPT_PREFIX] ?? '';
		return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
			? substr($key, strlen($prefix))
			: $key;
	}

	// #region connection

	/**
	 * Connects, which here only marks the client as connected.
	 *
	 * @param string $host
	 *   The server host, which is not dialled.
	 * @param int $port
	 *   The server port, which is not dialled.
	 * @param float $timeout
	 *   The connect timeout, which is ignored.
	 * @param ?string $persistent_id
	 *   The persistent connection id, which is ignored.
	 * @param int $retry_interval
	 *   The retry interval, which is ignored.
	 * @param float $read_timeout
	 *   The read timeout, which is ignored.
	 * @param ?array $context
	 *   The stream context, which is ignored.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function connect(
		string $host,
		int $port = 6379,
		float $timeout = 0.0,
		?string $persistent_id = null,
		int $retry_interval = 0,
		float $read_timeout = 0.0,
		?array $context = null,
	): bool {
		$this->connected = true;
		return true;
	}

	/**
	 * Connects with a persistent id, which behaves as connect().
	 *
	 * @param string $host
	 *   The server host, which is not dialled.
	 * @param int $port
	 *   The server port, which is not dialled.
	 * @param float $timeout
	 *   The connect timeout, which is ignored.
	 * @param ?string $persistent_id
	 *   The persistent connection id, which is ignored.
	 * @param int $retry_interval
	 *   The retry interval, which is ignored.
	 * @param float $read_timeout
	 *   The read timeout, which is ignored.
	 * @param ?array $context
	 *   The stream context, which is ignored.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function pconnect(
		string $host,
		int $port = 6379,
		float $timeout = 0.0,
		?string $persistent_id = null,
		int $retry_interval = 0,
		float $read_timeout = 0.0,
		?array $context = null,
	): bool {
		return $this->connect(
			$host,
			$port,
			$timeout,
			$persistent_id,
			$retry_interval,
			$read_timeout,
			$context,
		);
	}

	/**
	 * An alias of connect().
	 *
	 * @param string $host
	 *   The server host, which is not dialled.
	 * @param int $port
	 *   The server port, which is not dialled.
	 * @param float $timeout
	 *   The connect timeout, which is ignored.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function open(string $host, int $port = 6379, float $timeout = 0.0): bool
	{
		return $this->connect($host, $port, $timeout);
	}

	/**
	 * An alias of connect() with a persistent connection.
	 *
	 * @param string $host
	 *   The server host, which is not dialled.
	 * @param int $port
	 *   The server port, which is not dialled.
	 * @param float $timeout
	 *   The connect timeout, which is ignored.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function popen(string $host, int $port = 6379, float $timeout = 0.0): bool
	{
		return $this->connect($host, $port, $timeout);
	}

	/**
	 * Authenticates; there is no server, so any credentials are accepted.
	 *
	 * @param mixed $credentials
	 *   The password or the user and password pair, which is ignored.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function auth(mixed $credentials): bool
	{
		return true;
	}

	/**
	 * Selects the database whose keys later commands act on.
	 *
	 * @param int $db
	 *   The database index.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function select(int $db): bool
	{
		$this->db = max(0, $db);
		return true;
	}

	/**
	 * Marks the client as disconnected.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function close(): bool
	{
		$this->connected = false;
		return true;
	}

	/**
	 * Whether connect() has been called and close() has not.
	 *
	 * @return bool
	 *   TRUE after connect().
	 */
	public function isConnected(): bool
	{
		return $this->connected;
	}

	/**
	 * Answers a ping.
	 *
	 * @param ?string $message
	 *   An optional message to echo back.
	 *
	 * @return mixed
	 *   The message when one was given, otherwise TRUE.
	 */
	public function ping(?string $message = null): mixed
	{
		return $message ?? true;
	}

	/**
	 * Sets a client option, such as the key prefix.
	 *
	 * @param int $option
	 *   The option constant.
	 * @param mixed $value
	 *   The value, stored as a string.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function setOption(int $option, mixed $value): bool
	{
		$this->options[$option] = $value;
		return true;
	}

	/**
	 * Reads a client option.
	 *
	 * @param int $option
	 *   The option constant.
	 *
	 * @return mixed
	 *   The option, or FALSE when it was never set.
	 */
	public function getOption(int $option): mixed
	{
		return $this->options[$option] ?? false;
	}

	/**
	 * The last error a command recorded.
	 *
	 * @return ?string
	 *   The error text, or NULL.
	 */
	public function getLastError(): ?string
	{
		return $this->lastError;
	}

	/**
	 * Forgets the last error.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function clearLastError(): bool
	{
		$this->lastError = null;
		return true;
	}

	// #endregion

	// #region strings

	/**
	 * Reads a string key.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return string|false
	 *   The value, or FALSE when the key does not exist.
	 */
	public function get(string $key): string|false
	{
		$row = $this->store()->read($this->db, $this->keyOf($key), $this->now());
		return $row === null ? false : $row['v'];
	}

	/**
	 * Set.
	 *
	 * @param string $key
	 *   The key.
	 * @param mixed $value
	 *   The value, stored as a string.
	 * @param mixed $options
	 *   Seconds to live, or the phpredis option array: EX, PX, EXAT, PXAT, NX, XX, KEEPTTL, GET.
	 *
	 * @return mixed
	 *   TRUE, FALSE when NX or XX was not met, or the old value with GET.
	 */
	public function set(string $key, mixed $value, mixed $options = null): mixed
	{
		$expire = null;
		$nx = false;
		$xx = false;
		$get = false;
		$keep = false;
		if (is_int($options)) {
			$expire = $this->now() + $options;
		} elseif (is_array($options)) {
			foreach ($options as $name => $given) {
				$flag = strtoupper((string) (is_int($name) ? $given : $name));
				match ($flag) {
					'EX' => ($expire = $this->now() + (int) $given),
					'PX' => ($expire = $this->now() + intdiv((int) $given, 1000)),
					'EXAT' => ($expire = (int) $given),
					'PXAT' => ($expire = intdiv((int) $given, 1000)),
					'NX' => ($nx = true),
					'XX' => ($xx = true),
					'GET' => ($get = true),
					'KEEPTTL' => ($keep = true),
					default => null,
				};
			}
		}
		$full = $this->keyOf($key);
		$old = $this->store()->read($this->db, $full, $this->now());
		if (($nx && $old !== null) || ($xx && $old === null)) {
			return $get ? ($old === null ? false : $old['v']) : false;
		}
		if ($keep && $old !== null) {
			$expire = $old['expire'];
		}
		$this->store()->write($this->db, $full, $this->text($value), $expire);
		$this->purge();
		return $get ? ($old === null ? false : $old['v']) : true;
	}

	/**
	 * Sets a key with a lifetime in seconds.
	 *
	 * @param string $key
	 *   The key.
	 * @param int $ttl
	 *   The lifetime.
	 * @param mixed $value
	 *   The value, stored as a string.
	 *
	 * @return bool
	 *   TRUE on success.
	 */
	public function setex(string $key, int $ttl, mixed $value): bool
	{
		if ($ttl <= 0) {
			$this->lastError = 'ERR invalid expire time in \'setex\' command';
			return false;
		}
		return $this->set($key, $value, ['EX' => $ttl]) === true;
	}

	/**
	 * Sets a key with a lifetime in milliseconds.
	 *
	 * @param string $key
	 *   The key.
	 * @param int $ttl
	 *   The lifetime.
	 * @param mixed $value
	 *   The value, stored as a string.
	 *
	 * @return bool
	 *   TRUE on success.
	 */
	public function psetex(string $key, int $ttl, mixed $value): bool
	{
		return $ttl > 0 && $this->set($key, $value, ['PX' => $ttl]) === true;
	}

	/**
	 * Sets a key only when it does not exist.
	 *
	 * @param string $key
	 *   The key.
	 * @param mixed $value
	 *   The value, stored as a string.
	 *
	 * @return bool
	 *   TRUE when the key was set.
	 */
	public function setnx(string $key, mixed $value): bool
	{
		return $this->set($key, $value, ['NX']) === true;
	}

	/**
	 * Sets a key and returns what it held.
	 *
	 * @param string $key
	 *   The key.
	 * @param mixed $value
	 *   The value, stored as a string.
	 *
	 * @return string|false
	 *   The old value, or FALSE when there was none.
	 */
	public function getSet(string $key, mixed $value): string|false
	{
		$old = $this->get($key);
		$this->set($key, $value);
		return $old;
	}

	/**
	 * Reads several keys.
	 *
	 * @param array<int, string> $keys
	 *   The keys.
	 *
	 * @return array<int, string|false>
	 *   The values in key order, FALSE for an absent key.
	 */
	public function mget(array $keys): array
	{
		return array_map(fn($key) => $this->get((string) $key), array_values($keys));
	}

	/**
	 * Sets several keys.
	 *
	 * @param array<string, mixed> $pairs
	 *   The values keyed by key.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function mset(array $pairs): bool
	{
		foreach ($pairs as $key => $value) {
			$this->set((string) $key, $value);
		}
		return true;
	}

	/**
	 * Appends to a string key, creating it when absent.
	 *
	 * @param string $key
	 *   The key.
	 * @param string $value
	 *   The value, stored as a string.
	 *
	 * @return int|false
	 *   The new length, or FALSE when the key holds nothing a string can extend.
	 */
	public function append(string $key, string $value): int|false
	{
		$now = $this->get($key);
		$next = ($now === false ? '' : $now) . $value;
		$row = $this->store()->read($this->db, $this->keyOf($key), $this->now());
		$this->store()->write($this->db, $this->keyOf($key), $next, $row['expire'] ?? null);
		return strlen($next);
	}

	/**
	 * The length of a string key, or 0 when absent.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return int
	 *   The length in bytes.
	 */
	public function strlen(string $key): int
	{
		$value = $this->get($key);
		return $value === false ? 0 : strlen($value);
	}

	/**
	 * Adds one to an integer key.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return int|false
	 *   The new value, or FALSE when the key is not an integer.
	 */
	public function incr(string $key): int|false
	{
		return $this->step($key, 1);
	}

	/**
	 * Adds an amount to an integer key.
	 *
	 * @param string $key
	 *   The key.
	 * @param int $by
	 *   The amount to add.
	 *
	 * @return int|false
	 *   The new value, or FALSE when the key is not an integer.
	 */
	public function incrBy(string $key, int $by): int|false
	{
		return $this->step($key, $by);
	}

	/**
	 * Subtracts one from an integer key.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return int|false
	 *   The new value, or FALSE when the key is not an integer.
	 */
	public function decr(string $key): int|false
	{
		return $this->step($key, -1);
	}

	/**
	 * Subtracts an amount from an integer key.
	 *
	 * @param string $key
	 *   The key.
	 * @param int $by
	 *   The amount to add.
	 *
	 * @return int|false
	 *   The new value, or FALSE when the key is not an integer.
	 */
	public function decrBy(string $key, int $by): int|false
	{
		return $this->step($key, -$by);
	}

	/**
	 * Adds a signed amount to an integer key, creating it at zero when absent.
	 *
	 * @param string $key
	 *   The key.
	 * @param int $by
	 *   The amount to add.
	 *
	 * @return int|false
	 *   The new value, or FALSE when the key is not an integer.
	 */
	private function step(string $key, int $by): int|false
	{
		$full = $this->keyOf($key);
		$row = $this->store()->read($this->db, $full, $this->now());
		$was = $row === null ? '0' : $row['v'];
		if (preg_match('/^-?\d+$/', $was) !== 1) {
			$this->lastError = 'ERR value is not an integer or out of range';
			return false;
		}
		$next = (int) $was + $by;
		$this->store()->write($this->db, $full, (string) $next, $row['expire'] ?? null);
		return $next;
	}

	// #endregion

	// #region keys and expiry

	/**
	 * Deletes keys.
	 *
	 * @param array|string $key
	 *   The key.
	 * @param string ...$more
	 *   Further keys.
	 *
	 * @return int
	 *   How many keys were deleted.
	 */
	public function del(array|string $key, string ...$more): int
	{
		$count = 0;
		foreach ($this->keysOf($key, $more) as $one) {
			if ($this->store()->read($this->db, $this->keyOf($one), $this->now()) !== null) {
				$this->store()->delete($this->db, $this->keyOf($one));
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Deletes keys, as del() does; there is no background free to defer to.
	 *
	 * @param array|string $key
	 *   The key.
	 * @param string ...$more
	 *   Further keys.
	 *
	 * @return int
	 *   How many keys were deleted.
	 */
	public function unlink(array|string $key, string ...$more): int
	{
		return $this->del($key, ...$more);
	}

	/**
	 * Counts how many of the keys exist.
	 *
	 * @param array|string $key
	 *   The key.
	 * @param string ...$more
	 *   Further keys.
	 *
	 * @return int
	 *   How many of the keys exist.
	 */
	public function exists(array|string $key, string ...$more): int
	{
		$count = 0;
		foreach ($this->keysOf($key, $more) as $one) {
			if ($this->store()->read($this->db, $this->keyOf($one), $this->now()) !== null) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Gives a key a lifetime in seconds.
	 *
	 * @param string $key
	 *   The key.
	 * @param int $ttl
	 *   The lifetime.
	 *
	 * @return bool
	 *   TRUE when the key exists and now expires.
	 */
	public function expire(string $key, int $ttl): bool
	{
		return $this->expireAt($key, $this->now() + $ttl);
	}

	/**
	 * Gives a key a lifetime in milliseconds.
	 *
	 * @param string $key
	 *   The key.
	 * @param int $ttl
	 *   The lifetime.
	 *
	 * @return bool
	 *   TRUE when the key exists and now expires.
	 */
	public function pexpire(string $key, int $ttl): bool
	{
		return $this->expireAt($key, $this->now() + intdiv($ttl, 1000));
	}

	/**
	 * Gives a key an expiry as a unix time in seconds.
	 *
	 * @param string $key
	 *   The key.
	 * @param int $timestamp
	 *   The unix time in seconds.
	 *
	 * @return bool
	 *   TRUE when the key exists and now expires.
	 */
	public function expireAt(string $key, int $timestamp): bool
	{
		$full = $this->keyOf($key);
		$row = $this->store()->read($this->db, $full, $this->now());
		if ($row === null) {
			return false;
		}
		// a time already past removes the key, as Redis does
		if ($timestamp <= $this->now()) {
			$this->store()->delete($this->db, $full);
			return true;
		}
		$this->store()->write($this->db, $full, $row['v'], $timestamp);
		return true;
	}

	/**
	 * Gives a key an expiry as a unix time in milliseconds.
	 *
	 * @param string $key
	 *   The key.
	 * @param int $timestampMs
	 *   The unix time in milliseconds.
	 *
	 * @return bool
	 *   TRUE when the key exists and now expires.
	 */
	public function pexpireAt(string $key, int $timestampMs): bool
	{
		return $this->expireAt($key, intdiv($timestampMs, 1000));
	}

	/**
	 * Removes a key's expiry.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return bool
	 *   TRUE when an expiry was removed.
	 */
	public function persist(string $key): bool
	{
		$full = $this->keyOf($key);
		$row = $this->store()->read($this->db, $full, $this->now());
		if ($row === null || $row['expire'] === null) {
			return false;
		}
		$this->store()->write($this->db, $full, $row['v'], null);
		return true;
	}

	/**
	 * The seconds a key has left, with the two sentinel values Redis uses.
	 *
	 * The answer is -2 for a missing key, -1 for one with no expiry, else the seconds left.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return int
	 *   Seconds left, -1 for no expiry, -2 for an absent key.
	 */
	public function ttl(string $key): int
	{
		$row = $this->store()->read($this->db, $this->keyOf($key), $this->now());
		if ($row === null) {
			return -2;
		}
		return $row['expire'] === null ? -1 : max(0, $row['expire'] - $this->now());
	}

	/**
	 * The milliseconds a key has left.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return int
	 *   Milliseconds left, -1 for no expiry, -2 for an absent key.
	 */
	public function pttl(string $key): int
	{
		$seconds = $this->ttl($key);
		return $seconds < 0 ? $seconds : $seconds * 1000;
	}

	/**
	 * The type of a key.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return int
	 *   The REDIS_STRING constant, or REDIS_NOT_FOUND.
	 */
	public function type(string $key): int
	{
		return $this->store()->read($this->db, $this->keyOf($key), $this->now()) === null
			? self::REDIS_NOT_FOUND
			: self::REDIS_STRING;
	}

	/**
	 * Lists the keys matching a glob pattern.
	 *
	 * @param string $pattern
	 *   The glob pattern.
	 *
	 * @return array<int, string>
	 *   The keys.
	 */
	public function keys(string $pattern): array
	{
		$regex = self::globToRegex($this->keyOf($pattern));
		$found = [];
		foreach (array_keys($this->store()->all($this->db, $this->now())) as $key) {
			if (preg_match($regex, $key) === 1) {
				$found[] = $this->unprefixed($key);
			}
		}
		sort($found);
		return $found;
	}

	/**
	 * Answers one batch holding every match, then sets the iterator to 0.
	 *
	 * A caller loops until the iterator is the integer 0, which this reaches on the first call, so a
	 * module's `do { scan() } while ($it !== 0)` runs once. FALSE is what phpredis answers when an
	 * iteration is already over.
	 *
	 * @param int|string|null $iterator
	 *   The cursor, by reference.
	 * @param string|null $pattern
	 *   A glob, or NULL for every key.
	 * @param int $count
	 *   A batch size hint, which is ignored.
	 *
	 * @return array<int, string>|false
	 *   The keys of this page, or FALSE when the walk is over.
	 */
	public function scan(mixed &$iterator, ?string $pattern = null, int $count = 0): array|false
	{
		if ($iterator === 0 || $iterator === '0') {
			return false;
		}
		$iterator = 0;
		return $this->keys($pattern ?? '*');
	}

	/**
	 * How many keys the selected database holds.
	 *
	 * @return int
	 *   The number of live keys.
	 */
	public function dbSize(): int
	{
		return count($this->store()->all($this->db, $this->now()));
	}

	/**
	 * Removes every key of the selected database.
	 *
	 * @param ?bool $sync
	 *   Ignored; the removal is always synchronous.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function flushDb(?bool $sync = null): bool
	{
		foreach (array_keys($this->store()->all($this->db, $this->now())) as $key) {
			$this->store()->delete($this->db, $key);
		}
		return true;
	}

	/**
	 * Removes every key of every database.
	 *
	 * @param ?bool $sync
	 *   Ignored; the removal is always synchronous.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function flushAll(?bool $sync = null): bool
	{
		return $this->flushDb($sync);
	}

	// #endregion

	/**
	 * Anything else a Redis can do, which this one cannot.
	 *
	 * @param string $name
	 *   The method.
	 * @param array $arguments
	 *   The command arguments.
	 *
	 * @return never
	 *   Never returns.
	 */
	public function __call(string $name, array $arguments): never
	{
		if (class_exists(Degradation::class)) {
			Degradation::record(
				'redis',
				"the Redis stand-in answers string commands from the site database; $name is not one of them",
				'blocked',
			);
		}
		throw new RedisException("$name is not supported by the drupflare Redis stand-in");
	}

	/**
	 * A redis glob as a regular expression.
	 *
	 * @param string $glob
	 *   A pattern using `*`, `?`, `[abc]`, `[^abc]` and a backslash escape.
	 *
	 * @return string
	 *   A regular expression with delimiters.
	 */
	public static function globToRegex(string $glob): string
	{
		$out = '';
		$length = strlen($glob);
		for ($i = 0; $i < $length; $i++) {
			$c = $glob[$i];
			if ($c === '*') {
				$out .= '.*';
			} elseif ($c === '?') {
				$out .= '.';
			} elseif ($c === '\\' && $i + 1 < $length) {
				$out .= preg_quote($glob[++$i], '/');
			} elseif ($c === '[') {
				$close = strpos($glob, ']', $i + 1);
				if ($close === false) {
					$out .= '\\[';
					continue;
				}
				$body = substr($glob, $i + 1, $close - $i - 1);
				$negate = str_starts_with($body, '^');
				$body = $negate ? substr($body, 1) : $body;
				$out .= '[' . ($negate ? '^' : '') . addcslashes($body, '\\]^') . ']';
				$i = $close;
			} else {
				$out .= preg_quote($c, '/');
			}
		}
		return '/^' . $out . '$/s';
	}

	/**
	 * Flattens the key arguments of a multi-key command.
	 *
	 * @param array|string $key
	 *   The key.
	 * @param array $more
	 *   Further keys.
	 *
	 * @return array<int, string>
	 *   The keys.
	 */
	private function keysOf(array|string $key, array $more): array
	{
		return [...is_array($key) ? array_values($key) : [$key], ...$more];
	}

	/**
	 * Casts a value to the string Redis would store.
	 *
	 * @param mixed $value
	 *   The value, stored as a string.
	 *
	 * @return string
	 *   The string form.
	 */
	private function text(mixed $value): string
	{
		return match (true) {
			is_string($value) => $value,
			is_bool($value) => $value ? '1' : '',
			is_scalar($value) => (string) $value,
			default => '',
		};
	}

	/**
	 * Drops what has expired, at most once a minute per object.
	 */
	private function purge(): void
	{
		static $last = 0;
		$now = $this->now();
		if ($now - $last < 60) {
			return;
		}
		$last = $now;
		$this->store()->purge($this->db, $now);
	}
}
