<?php

declare(strict_types=1);

namespace Drupal\drupflare\SimpleSaml;

use Drupal;
use SimpleSAML\Store\StoreInterface;

/**
 * A SimpleSAMLphp datastore kept in the site database.
 *
 * SimpleSAMLphp's own stores need a PDO driver, Predis or memcache, and none of them exists in this
 * interpreter; `phpsession` is refused by simplesamlphp_auth. Name this class as the store in the
 * SimpleSAMLphp configuration and sessions, AuthnRequest state and assertion ids live in Drupal's
 * expirable key value table:
 *
 * ```php
 * 'store.type' => 'Drupal\drupflare\SimpleSaml\DatabaseStore',
 * ```
 *
 * SimpleSAMLphp accepts a class name there because `StoreFactory` falls through to
 * `Module::resolveClass()` for any value that is not a built-in type name.
 */
class DatabaseStore implements StoreInterface
{
	/**
	 * Seconds standing in for an entry that never expires.
	 *
	 * SimpleSAMLphp keeps an unexpiring entry for as long as the store lives; a year stands in.
	 */
	private const NEVER_SECONDS = 31536000;

	/**
	 * The longest key stored as given.
	 *
	 * The key column is 128 bytes, and a longer key is stored under its digest.
	 */
	private const MAX_KEY = 128;

	/**
	 * {@inheritdoc}
	 */
	public function get(string $type, string $key): mixed
	{
		return $this->bin($type)->get($this->name($key));
	}

	/**
	 * {@inheritdoc}
	 */
	public function set(string $type, string $key, mixed $value, ?int $expire = null): void
	{
		$seconds = $expire === null ? self::NEVER_SECONDS : max(1, $expire - time());
		$this->bin($type)->setWithExpire($this->name($key), $value, $seconds);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $type, string $key): void
	{
		$this->bin($type)->delete($this->name($key));
	}

	/**
	 * The expirable collection for one SimpleSAMLphp data type.
	 *
	 * @param string $type
	 *   The SimpleSAMLphp data type.
	 *
	 * @return object
	 *   The key value store holding that type.
	 */
	protected function bin(string $type): object
	{
		return Drupal::service('keyvalue.expirable')->get('drupflare_simplesaml_' . $type);
	}

	/**
	 * The key as stored, digested when it is longer than the column.
	 *
	 * @param string $key
	 *   The SimpleSAMLphp key.
	 *
	 * @return string
	 *   The key, or its digest.
	 */
	protected function name(string $key): string
	{
		return strlen($key) <= self::MAX_KEY ? $key : 'sha256:' . hash('sha256', $key);
	}
}
