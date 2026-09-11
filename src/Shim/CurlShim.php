<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use Drupal\drupflare\Http\ParkFetchHandler;
use Drupal\drupflare\Queue\CfwDeferredHttp;
use GuzzleHttp\Psr7\Request;

/**
 * The `curl_*` subset, over CfwDeferredHttp.
 *
 * Five functions plus their error pair: init, setopt, exec, getinfo, close. That is
 * not all of curl -- it is the subset ordinary contrib code actually uses, and every option outside
 * it is refused by name rather than ignored.
 *
 * Ignoring an option is the failure mode this avoids. `CURLOPT_SSL_VERIFYPEER => false` silently
 * dropped is a security change the caller believes it made; `CURLOPT_TIMEOUT` silently dropped is a
 * hang the caller believes it guarded. So an unrecognised option throws with its numeric constant
 * in the message.
 *
 * A handle is a plain array, not a resource: there is nothing to open. Nothing leaves the isolate
 * until exec(), which goes through {@see ParkFetchHandler} where the runtime can park and through
 * `CfwDeferredHttp` where it cannot. On the deferred path a first call answers 202 from the queue.
 * **A 202 is not a body.** exec() returns FALSE for it and getinfo() reports the 202 under
 * `cfw_deferred`, so a caller that needs the bytes can tell "queued" from "the server returned
 * nothing".
 */
final class CurlShim
{
	/**
	 * The options this shim understands, mapped to handle keys.
	 *
	 * Numeric literals because the `curl` extension is not loaded, so the CURLOPT_* constants do not
	 * exist to compare against. The values are curl's own and are stable ABI.
	 */
	const OPTIONS = [
		// CURLOPT_URL
		10002 => 'url',
		// CURLOPT_POSTFIELDS
		10015 => 'body',
		// CURLOPT_HTTPHEADER
		10023 => 'headers',
		// CURLOPT_CUSTOMREQUEST
		10036 => 'method',
		// CURLOPT_POST
		47 => 'post',
		// CURLOPT_RETURNTRANSFER
		19913 => 'returntransfer',
		// CURLOPT_FOLLOWLOCATION
		52 => 'followlocation',
	];

	/**
	 * CURLE_OK.
	 */
	const CURLE_OK = 0;

	/**
	 * CURLE_COULDNT_CONNECT, which is the honest code for "the queue refused it".
	 */
	const CURLE_COULDNT_CONNECT = 7;

	/**
	 * The handler requests go through; injectable so the suite can drive it without a host.
	 *
	 * Typed as a callable rather than as `CfwDeferredHttp`, because the default is now whichever
	 * transport the runtime can actually serve. Both satisfy Guzzle's handler shape.
	 *
	 * @var callable(\Psr\Http\Message\RequestInterface, array): \GuzzleHttp\Promise\PromiseInterface
	 */
	private $handler;

	/**
	 * Builds the shim over a handler.
	 *
	 * **THE DEFAULT PARKS WHERE IT CAN, and the docblock used to say it could not.** The shim was
	 * written against a runtime where PHP could not await, so `curl_exec()` queued and returned
	 * FALSE with `CURLE_COULDNT_CONNECT` on the first call for a URL. That is honest and it is
	 * useless to an SDK: Stripe authorises a payment inside one submit handler and has nowhere to
	 * put a second attempt. `ext/cfwpark` suspends the PHP call and the Worker performs the fetch,
	 * so the answer now arrives inside the same `curl_exec()` that asked for it.
	 *
	 * The deferred transport stays as the fallback, chosen here and again inside
	 * {@see ParkFetchHandler} when a park is refused mid-call -- so a build without the extension,
	 * a host without the loop, and a frame the park cannot splice all degrade to exactly what
	 * shipped before rather than to an error.
	 *
	 * @param callable|null $handler
	 *   The handler, or NULL to pick the best the runtime offers.
	 */
	public function __construct(?callable $handler = null)
	{
		$this->handler =
			$handler ??
			(ParkFetchHandler::available() ? new ParkFetchHandler() : new CfwDeferredHttp());
	}

	/**
	 * Shims curl_init().
	 *
	 * @param string|null $url
	 *   Optional URL, as curl_init() accepts.
	 *
	 * @return array
	 *   A handle.
	 */
	public function init(?string $url = null): array
	{
		ShimRegistry::assertRouted('curl_init');
		return [
			'url' => $url ?? '',
			'method' => '',
			'headers' => [],
			'body' => '',
			'post' => false,
			'returntransfer' => false,
			'followlocation' => false,
			'errno' => self::CURLE_OK,
			'error' => '',
			'info' => [],
			'executed' => false,
		];
	}

	/**
	 * Shims curl_setopt().
	 *
	 * @param array $handle
	 *   The handle, by reference, as curl_setopt() mutates it.
	 * @param int $option
	 *   A CURLOPT_* value.
	 * @param mixed $value
	 *   The value.
	 *
	 * @return bool
	 *   TRUE; anything not understood throws instead of returning FALSE.
	 *
	 * @throws ShimRefusal
	 *   When the option is not in self::OPTIONS.
	 */
	public function setopt(array &$handle, int $option, mixed $value): bool
	{
		ShimRegistry::assertRouted('curl_setopt');
		$key = self::OPTIONS[$option] ?? null;
		if ($key === null) {
			// naming the number is the whole value of this branch: a silently dropped
			// VERIFYPEER or TIMEOUT is a change the caller believes it made
			throw new ShimRefusal(
				'curl_setopt',
				sprintf(
					'option %d is not implemented by this shim, and ignoring it would silently change behaviour the caller asked for.',
					$option,
				),
				'one of the ' . count(self::OPTIONS) . ' options CurlShim::OPTIONS lists',
			);
		}
		if ($key === 'headers') {
			$handle['headers'] = self::parseHeaderList(is_array($value) ? $value : [$value]);
			return true;
		}
		if ($key === 'body') {
			$handle['body'] = is_array($value) ? http_build_query($value) : (string) $value;
			return true;
		}
		if (in_array($key, ['post', 'returntransfer', 'followlocation'], true)) {
			$handle[$key] = (bool) $value;
			return true;
		}
		$handle[$key] = (string) $value;
		return true;
	}

	/**
	 * Shims curl_setopt_array().
	 *
	 * All-or-nothing: a partially applied option set is a request the caller did not ask
	 * for, so the first unrecognised option refuses the whole array and the handle is untouched.
	 *
	 * @param array $handle
	 *   The handle, by reference.
	 * @param array $options
	 *   Option => value.
	 *
	 * @return bool
	 *   TRUE when every option applied.
	 *
	 * @throws ShimRefusal
	 *   When any option is not understood.
	 */
	public function setoptArray(array &$handle, array $options): bool
	{
		ShimRegistry::assertRouted('curl_setopt_array');
		foreach (array_keys($options) as $option) {
			if (!array_key_exists((int) $option, self::OPTIONS)) {
				throw new ShimRefusal(
					'curl_setopt_array',
					sprintf(
						'option %d is not implemented, and applying the rest would build a request the caller did not describe.',
						(int) $option,
					),
					'one of the ' . count(self::OPTIONS) . ' options CurlShim::OPTIONS lists',
				);
			}
		}
		$staged = $handle;
		foreach ($options as $option => $value) {
			$this->setopt($staged, (int) $option, $value);
		}
		$handle = $staged;
		return true;
	}

	/**
	 * Shims curl_exec().
	 *
	 * @param array $handle
	 *   The handle, by reference; getinfo() reads what this leaves behind.
	 *
	 * @return string|bool
	 *   The body when one was available, TRUE when the caller did not ask for the transfer, or FALSE
	 *   when there is no body -- including the deferred case.
	 *
	 * @throws ShimRefusal
	 *   When no URL was set, because an empty request is not a request.
	 */
	public function exec(array &$handle): string|bool
	{
		ShimRegistry::assertRouted('curl_exec');
		$url = (string) ($handle['url'] ?? '');
		if ($url === '') {
			throw new ShimRefusal(
				'curl_exec',
				'no CURLOPT_URL was set, so there is nothing to request.',
				'curl_setopt($ch, CURLOPT_URL, $url)',
			);
		}

		$method = self::resolveMethod($handle);
		$request = new Request(
			$method,
			$url,
			$handle['headers'] ?? [],
			(string) ($handle['body'] ?? ''),
		);
		$response = ($this->handler)($request, [])->wait();

		$status = $response->getStatusCode();
		$body = (string) $response->getBody();
		$deferred = $response->getHeaderLine('x-cfw-deferred');

		$handle['executed'] = true;
		$handle['info'] = [
			'url' => $url,
			'http_code' => $status,
			'request_method' => $method,
			'size_download' => strlen($body),
			'content_type' => $response->getHeaderLine('content-type'),
			// not a curl field, so a caller can SEE that this never left
			'cfw_deferred' => $deferred,
		];

		// a 202 from the queue is not a body, and reporting it as one is the exact
		// indistinguishable-empty-result failure this layer exists to prevent
		if ($deferred !== '') {
			$handle['errno'] =
				$deferred === 'queued' ? self::CURLE_OK : self::CURLE_COULDNT_CONNECT;
			$handle['error'] =
				$deferred === 'queued'
					? 'request was queued rather than awaited; there is no body yet (CfwDeferredHttp)'
					: 'the deferred-fetch queue refused the request (CfwDeferredHttp)';
			return false;
		}

		$handle['errno'] = self::CURLE_OK;
		$handle['error'] = '';
		if (!($handle['returntransfer'] ?? false)) {
			return true;
		}
		return $body;
	}

	/**
	 * Shims curl_getinfo().
	 *
	 * @param array $handle
	 *   The handle.
	 * @param string|null $key
	 *   A single field, or NULL for all of them.
	 *
	 * @return mixed
	 *   The field, the whole array, or NULL for an unknown field.
	 *
	 * @throws ShimRefusal
	 *   When exec() has not run, because empty info would read as a failed request.
	 */
	public function getinfo(array $handle, ?string $key = null): mixed
	{
		ShimRegistry::assertRouted('curl_getinfo');
		if (!($handle['executed'] ?? false)) {
			throw new ShimRefusal(
				'curl_getinfo',
				'the handle has not been executed, and an empty info array is indistinguishable from a request that failed.',
				'curl_exec($ch) first',
			);
		}
		if ($key === null) {
			return $handle['info'];
		}
		return $handle['info'][$key] ?? null;
	}

	/**
	 * Shims curl_errno().
	 *
	 * @param array $handle
	 *   The handle.
	 *
	 * @return int
	 *   A CURLE_* value.
	 */
	public function errno(array $handle): int
	{
		ShimRegistry::assertRouted('curl_errno');
		return (int) ($handle['errno'] ?? self::CURLE_OK);
	}

	/**
	 * Shims curl_error().
	 *
	 * @param array $handle
	 *   The handle.
	 *
	 * @return string
	 *   The message; empty only when there was no error.
	 */
	public function error(array $handle): string
	{
		ShimRegistry::assertRouted('curl_error');
		return (string) ($handle['error'] ?? '');
	}

	/**
	 * Shims curl_close().
	 *
	 * @param array $handle
	 *   The handle, by reference; emptied so a reuse fails loudly.
	 */
	public function close(array &$handle): void
	{
		ShimRegistry::assertRouted('curl_close');
		$handle = [];
	}

	/**
	 * POST when the caller said so or supplied a body, GET otherwise.
	 */
	private static function resolveMethod(array $handle): string
	{
		$explicit = strtoupper((string) ($handle['method'] ?? ''));
		if ($explicit !== '') {
			return $explicit;
		}
		if (($handle['post'] ?? false) || (string) ($handle['body'] ?? '') !== '') {
			return 'POST';
		}
		return 'GET';
	}

	/**
	 * Converts headers: curl takes them as "Name: value" strings; PSR-7 wants a map.
	 */
	private static function parseHeaderList(array $lines): array
	{
		$out = [];
		foreach ($lines as $line) {
			$at = strpos((string) $line, ':');
			if ($at === false) {
				continue;
			}
			$name = trim(substr((string) $line, 0, $at));
			if ($name === '') {
				continue;
			}
			$out[$name] = trim(substr((string) $line, $at + 1));
		}
		return $out;
	}
}
