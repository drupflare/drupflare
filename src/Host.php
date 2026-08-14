<?php

declare(strict_types=1);

namespace Drupal\drupflare;

use Drupal\drupflare\Plugin\Mail\CfwMail;
use Drupal\drupflare\StreamWrapper\HttpsStreamWrapper;
use Throwable;

/**
 * The single seam between PHP and the Worker runtime.
 *
 * Every capability in this module goes through here rather than reaching for
 * vrzno_env() itself, for two reasons. The bridge is 32-bit (PHP_INT_SIZE 4) so
 * anything above 2^31 has to cross as a codec envelope or a string, and a
 * capability that forgets is a silent corruption rather than an error. And a host
 * function may simply be absent -- a Worker deployed without an email binding has
 * no cfwMail -- so "is this capability available" has exactly one answer, here,
 * instead of one per plugin.
 *
 * @see CfwMail
 * @see HttpsStreamWrapper
 */
final class Host
{
	/**
	 * Returns a host function installed on the PHP Module, or NULL.
	 *
	 * @param string $name
	 *   The Module key the runtime installed.
	 *
	 * @return mixed
	 *   The invokable, or NULL when the runtime did not install it.
	 */
	public static function fn(string $name): mixed
	{
		if (!function_exists('vrzno_env')) {
			return null;
		}
		$candidate = vrzno_env($name);
		// a vrzno-surfaced JS function is an object that is_callable() may not
		// recognise, so an object is accepted and allowed to fail at the call
		return is_object($candidate) || is_callable($candidate) ? $candidate : null;
	}

	/**
	 * Whether a capability is present in this deployment.
	 */
	public static function has(string $name): bool
	{
		return self::fn($name) !== null;
	}

	/**
	 * Calls a host function with a JSON payload and decodes the reply.
	 *
	 * @param string $name
	 *   The Module key.
	 * @param array $payload
	 *   The request. Encoded through pw_encode() so wide integers survive.
	 *
	 * @return array
	 *   The decoded reply, always with an 'ok' key.
	 */
	public static function call(string $name, array $payload): array
	{
		$invoke = self::fn($name);
		if ($invoke === null) {
			return [
				'ok' => false,
				'error' => sprintf('capability %s is not installed in this deployment', $name),
			];
		}

		$request = function_exists('pw_encode') ? pw_encode($payload) : $payload;
		$json = json_encode($request);
		if (!is_string($json)) {
			return [
				'ok' => false,
				'error' => 'could not encode the request: ' . json_last_error_msg(),
			];
		}

		try {
			$reply = $invoke($json);
		} catch (Throwable $e) {
			return ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
		}

		if (!is_string($reply)) {
			return [
				'ok' => false,
				'error' => sprintf(
					'host returned %s where a JSON string was expected',
					get_debug_type($reply),
				),
			];
		}
		$decoded = json_decode($reply, true);
		if (!is_array($decoded)) {
			return [
				'ok' => false,
				'error' => 'host reply was not a JSON object: ' . substr($reply, 0, 200),
			];
		}

		return function_exists('pw_decode') ? pw_decode($decoded) : $decoded;
	}
}
