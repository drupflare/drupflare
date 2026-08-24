<?php

declare(strict_types=1);

namespace Drupal\drupflare\Network;

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;

/**
 * The TCP half of the CFW network capability: one declared exchange, run between invocations.
 *
 * **THERE IS NO `connect()` / `read()` / `write()` / `close()` HERE, and that is the interpreter
 * rather than an omission.** `Host::call()` is `$reply = $invoke($json)`, and this build carries no
 * JSPI or Asyncify, so the wasm stack cannot suspend. A `read()` would have to block for bytes that
 * have not arrived. So the session shape is closed and what survives it is this: declare the whole
 * exchange, the host runs it in JavaScript between PHP runs, and the answer is readable on a later
 * invocation. It is the same cached -> deferred -> sync layering {@see CfwDeferredHttp} documents,
 * with the sync tier absent for the same reason.
 *
 * **The endpoint is the OPERATOR'S, never the caller's.** Module code names a protocol and an
 * operation; the host reads `REDIS_URL` / `SYSLOG_URL` for the host, port and credentials. Letting
 * PHP choose a `host:port` would put arbitrary outbound TCP behind anything that can call a host
 * function, which is a wider hole than the HTTP tier's because it is not confined to HTTP
 * semantics.
 *
 * Two protocols, because two shapes exist rather than to be a catalogue: `redis` has a reply and is
 * cached-or-deferred; `syslog` has none and is fire-and-forget, which is the shape this tier serves
 * best.
 */
final class CfwTcp
{
	/**
	 * Whether the host installed this capability at all.
	 *
	 * @return bool
	 *   TRUE when `cfwTcp` is present.
	 */
	public static function available(): bool
	{
		return Host::has('cfwTcp');
	}

	/**
	 * Runs one Redis command, from cache if a previous drain already ran it.
	 *
	 * **A CACHE BACKEND CANNOT BE BUILT ON THIS**, and that is worth saying where someone would try:
	 * a cache get has to answer inside the request that asked, and the first call here always
	 * misses. What this reaches is the deferrable half -- a counter, a publish, a write whose result
	 * nobody blocks on, or a read that a later request can use.
	 *
	 * @param array $args
	 *   The command and its arguments, e.g. `['GET', 'feature:flags']`.
	 *
	 * @return array
	 *   `['ok' => TRUE, 'value' => mixed]` when the answer is available, otherwise
	 *   `['ok' => FALSE, 'error' => string, 'queued' => bool]`. `queued` TRUE means the exchange was
	 *   accepted and a later invocation can read it.
	 */
	public static function redis(array $args): array
	{
		if (!self::available()) {
			return self::unavailable('redis');
		}

		$reply = Host::call('cfwTcp', ['protocol' => 'redis', 'args' => array_values($args)]);
		if (($reply['ok'] ?? false) !== true) {
			return [
				'ok' => false,
				'error' => (string) ($reply['error'] ?? 'the redis exchange was refused'),
				'queued' => (bool) ($reply['queued'] ?? false),
			];
		}

		// the host hands back the raw JSON the drain stored, so a caller gets PHP values rather
		// than a string it has to know to decode
		$decoded = json_decode((string) ($reply['body'] ?? 'null'), true);
		return ['ok' => true, 'value' => $decoded, 'queued' => false];
	}

	/**
	 * Ships one record to the configured collector, without waiting for it.
	 *
	 * Fire-and-forget is the honest shape for syslog over TCP -- the protocol never replies -- which
	 * makes it the one thing this tier does with no compromise at all.
	 *
	 * @param string $message
	 *   The free-form message text.
	 * @param string $severity
	 *   An RFC 5424 severity name, e.g. `info`, `warning`, `err`.
	 * @param array $extra
	 *   Optional `facility` and `msgId` overrides.
	 *
	 * @return bool
	 *   TRUE when the record was accepted for the next drain.
	 */
	public static function syslog(
		string $message,
		string $severity = 'info',
		array $extra = [],
	): bool {
		if (!self::available()) {
			self::unavailable('syslog');
			return false;
		}

		$record = ['message' => $message, 'severity' => $severity];
		foreach (['facility', 'msgId'] as $key) {
			if (isset($extra[$key])) {
				$record[$key] = $extra[$key];
			}
		}

		$reply = Host::call('cfwTcp', ['protocol' => 'syslog', 'record' => $record]);
		return ($reply['ok'] ?? false) === true;
	}

	/**
	 * Declares the gap once, so a site missing the capability says so in its status report.
	 *
	 * @param string $protocol
	 *   Which protocol was asked for.
	 *
	 * @return array
	 *   The refusal, in the shape {@see self::redis()} returns.
	 */
	private static function unavailable(string $protocol): array
	{
		$reason = sprintf(
			'cfwTcp is not installed by this deployment, so %s is unreachable. It is the TCP tier of the network capability and needs REDIS_URL or SYSLOG_URL set on the Worker.',
			$protocol,
		);
		Degradation::record('cfwTcp', $reason);
		return ['ok' => false, 'error' => $reason, 'queued' => false];
	}
}
