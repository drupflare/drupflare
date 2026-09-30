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
		// CURLOPT_HTTPGET
		80 => 'httpget',
		// CURLOPT_NOBODY
		44 => 'nobody',
		// CURLOPT_HEADER
		42 => 'header',
		// CURLOPT_TIMEOUT
		13 => 'timeout',
		// CURLOPT_TIMEOUT_MS
		155 => 'timeout_ms',
		// CURLOPT_CONNECTTIMEOUT
		78 => 'connecttimeout',
		// CURLOPT_CONNECTTIMEOUT_MS
		156 => 'connecttimeout_ms',
		// CURLOPT_SSL_VERIFYPEER
		64 => 'verifypeer',
		// CURLOPT_SSL_VERIFYHOST
		81 => 'verifyhost',
		// CURLOPT_CAINFO
		10065 => 'cainfo',
		// CURLOPT_HTTP_VERSION
		84 => 'http_version',
		// CURLOPT_SSLVERSION
		32 => 'sslversion',
		// CURLOPT_ENCODING
		10102 => 'encoding',
		// CURLOPT_FORBID_REUSE
		75 => 'forbid_reuse',
		// CURLOPT_NOSIGNAL
		99 => 'nosignal',
		// CURLOPT_USERAGENT
		10018 => 'useragent',
		// CURLOPT_USERPWD
		10005 => 'userpwd',
		// CURLOPT_HTTPAUTH
		107 => 'httpauth',
		// CURLOPT_PROXY
		10004 => 'proxy',
		// CURLOPT_HEADERFUNCTION
		20079 => 'headerfunction',
		// CURLOPT_WRITEFUNCTION
		20011 => 'writefunction',
		// CURLINFO_HEADER_OUT, which curl takes as an option
		2 => 'header_out',
	];

	/**
	 * The `CURLINFO_*` values curl_getinfo() takes, mapped to the info array's keys.
	 */
	const INFO = [
		// CURLINFO_EFFECTIVE_URL
		1048577 => 'url',
		// CURLINFO_RESPONSE_CODE, which is also CURLINFO_HTTP_CODE
		2097154 => 'http_code',
		// CURLINFO_CONTENT_TYPE
		1048594 => 'content_type',
		// CURLINFO_TOTAL_TIME
		3145731 => 'total_time',
		// CURLINFO_HEADER_SIZE
		2097163 => 'header_size',
		// CURLINFO_SIZE_DOWNLOAD
		3145736 => 'size_download',
		// CURLINFO_HEADER_OUT
		2 => 'request_header',
	];

	/**
	 * Options accepted and not acted on, each with why ignoring it changes nothing the caller asked for.
	 *
	 * @var array<string, string>
	 */
	const INERT = [
		'cainfo' => 'the platform verifies every certificate against its own trust store',
		'http_version' => 'fetch() negotiates the protocol',
		'sslversion' => 'fetch() negotiates TLS 1.2 or newer',
		'encoding' => 'fetch() decompresses the body itself',
		'forbid_reuse' => 'there is no connection to reuse',
		'nosignal' => 'there are no signals',
		'connecttimeout' => 'the host bounds the connection with the whole-request timeout',
		'connecttimeout_ms' => 'the host bounds the connection with the whole-request timeout',
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
			'httpget' => false,
			'nobody' => false,
			'header' => false,
			'returntransfer' => false,
			'followlocation' => false,
			'timeout_ms' => 0,
			'userpwd' => '',
			'headerfunction' => null,
			'writefunction' => null,
			'header_out' => false,
			'errno' => self::CURLE_OK,
			'error' => '',
			'info' => [],
			'executed' => false,
		];
	}

	/**
	 * Shims curl_reset(): every option back to its default, the URL included.
	 *
	 * @param array $handle
	 *   The handle, by reference.
	 */
	public function reset(array &$handle): void
	{
		ShimRegistry::assertRouted('curl_reset');
		$handle = $this->init();
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
		switch ($key) {
			case 'headers':
				$handle['headers'] = self::parseHeaderList(is_array($value) ? $value : [$value]);
				return true;

			case 'body':
				$handle['body'] = is_array($value) ? http_build_query($value) : (string) $value;
				return true;

			case 'post':
			case 'httpget':
			case 'nobody':
			case 'header':
			case 'returntransfer':
			case 'followlocation':
			case 'header_out':
				$handle[$key] = (bool) $value;
				// the three method flags are exclusive in curl: the last one set wins
				if ($value && in_array($key, ['post', 'httpget', 'nobody'], true)) {
					foreach (['post', 'httpget', 'nobody'] as $flag) {
						$handle[$flag] = $flag === $key;
					}
				}
				return true;

			case 'timeout':
				$handle['timeout_ms'] = max(0, (int) $value) * 1000;
				return true;

			case 'timeout_ms':
				$handle['timeout_ms'] = max(0, (int) $value);
				return true;

			case 'verifypeer':
				if (!$value) {
					self::refuseVerify($option, 'CURLOPT_SSL_VERIFYPEER');
				}
				return true;

			case 'verifyhost':
				// 2 is the check; 1 is deprecated and treated as 2 by curl itself
				if ((int) $value === 0) {
					self::refuseVerify($option, 'CURLOPT_SSL_VERIFYHOST');
				}
				return true;

			case 'useragent':
				$handle['headers']['User-Agent'] = (string) $value;
				return true;

			case 'userpwd':
				$handle['userpwd'] = (string) $value;
				return true;

			case 'httpauth':
				// CURLAUTH_BASIC is 1 and CURLAUTH_ANY/ANYSAFE include it; nothing else is spoken
				if (((int) $value & 1) === 0) {
					throw new ShimRefusal(
						'curl_setopt',
						sprintf(
							'CURLOPT_HTTPAUTH %d names no scheme this shim sends; only basic authentication is supported.',
							(int) $value,
						),
						'CURLAUTH_BASIC',
					);
				}
				return true;

			case 'proxy':
				if ((string) $value !== '') {
					throw new ShimRefusal(
						'curl_setopt',
						'CURLOPT_PROXY cannot be honoured: every request leaves through the platform, and sending it direct would bypass a proxy the caller relies on.',
						'no proxy, or a fetch the platform makes directly',
					);
				}
				return true;

			case 'headerfunction':
			case 'writefunction':
				$handle[$key] = is_callable($value) ? $value : null;
				return true;
		}
		if (isset(self::INERT[$key])) {
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
	 *   The body when CURLOPT_RETURNTRANSFER is set. Otherwise TRUE, with the body printed or handed
	 *   to CURLOPT_WRITEFUNCTION the way curl delivers it. FALSE when there is no body, including the
	 *   deferred case.
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
		$headers = $handle['headers'] ?? [];
		if (($handle['userpwd'] ?? '') !== '' && !isset($headers['Authorization'])) {
			$headers['Authorization'] = 'Basic ' . base64_encode((string) $handle['userpwd']);
		}
		$request = new Request($method, $url, $headers, (string) ($handle['body'] ?? ''));
		$options = [];
		if (($handle['timeout_ms'] ?? 0) > 0) {
			$options['timeout'] = $handle['timeout_ms'] / 1000;
		}
		if ($handle['followlocation'] ?? false) {
			$options['allow_redirects'] = true;
		}
		$response = ($this->handler)($request, $options)->wait();

		$status = $response->getStatusCode();
		$body = $method === 'HEAD' ? '' : (string) $response->getBody();
		$deferred = $response->getHeaderLine('x-cfw-deferred');
		$headerLines = ['HTTP/1.1 ' . $status . ' ' . $response->getReasonPhrase() . "\r\n"];
		foreach ($response->getHeaders() as $name => $values) {
			foreach ($values as $value) {
				$headerLines[] = $name . ': ' . $value . "\r\n";
			}
		}
		$headerLines[] = "\r\n";
		$headerBlock = implode('', $headerLines);

		$handle['executed'] = true;
		$handle['info'] = [
			'url' => $url,
			'http_code' => $status,
			'request_method' => $method,
			'size_download' => strlen($body),
			'header_size' => strlen($headerBlock),
			'total_time' => 0.0,
			'content_type' => $response->getHeaderLine('content-type'),
			// not a curl field, so a caller can SEE that this never left
			'cfw_deferred' => $deferred,
		];
		if ($handle['header_out'] ?? false) {
			$handle['info']['request_header'] = self::requestHeaderBlock($request);
		}

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
		if (is_callable($handle['headerfunction'] ?? null)) {
			foreach ($headerLines as $line) {
				$handle['headerfunction']($handle, $line);
			}
		}
		$output = $handle['header'] ?? false ? $headerBlock . $body : $body;
		if (is_callable($handle['writefunction'] ?? null)) {
			if ($output !== '') {
				$handle['writefunction']($handle, $output);
			}
			return true;
		}
		if ($handle['returntransfer'] ?? false) {
			return $output;
		}
		echo $output;
		return true;
	}

	/**
	 * Shims curl_getinfo().
	 *
	 * @param array $handle
	 *   The handle.
	 * @param int|string|null $key
	 *   A CURLINFO_* value, an info-array field name, or NULL for all of them.
	 *
	 * @return mixed
	 *   The field, the whole array, or NULL for an unknown field.
	 *
	 * @throws ShimRefusal
	 *   When exec() has not run, because empty info would read as a failed request.
	 */
	public function getinfo(array $handle, int|string|null $key = null): mixed
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
		if (is_int($key) || ctype_digit($key)) {
			$key = self::INFO[(int) $key] ?? '';
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
		if ($handle['nobody'] ?? false) {
			return 'HEAD';
		}
		if ($handle['httpget'] ?? false) {
			return 'GET';
		}
		if (($handle['post'] ?? false) || (string) ($handle['body'] ?? '') !== '') {
			return 'POST';
		}
		return 'GET';
	}

	/**
	 * The request as curl reports it under CURLINFO_HEADER_OUT.
	 */
	private static function requestHeaderBlock(Request $request): string
	{
		$target = $request->getUri()->getPath() ?: '/';
		$query = $request->getUri()->getQuery();
		$block = $request->getMethod() . ' ' . $target . ($query !== '' ? '?' . $query : '');
		$block .= " HTTP/1.1\r\nHost: " . $request->getUri()->getHost() . "\r\n";
		foreach ($request->getHeaders() as $name => $values) {
			if (strcasecmp((string) $name, 'Host') !== 0) {
				$block .= $name . ': ' . implode(', ', $values) . "\r\n";
			}
		}
		return $block . "\r\n";
	}

	/**
	 * Refuses switching certificate verification off, which the platform cannot do.
	 *
	 * @throws ShimRefusal
	 *   Always.
	 */
	private static function refuseVerify(int $option, string $name): never
	{
		throw new ShimRefusal(
			'curl_setopt',
			sprintf(
				'%s (option %d) off cannot be honoured: fetch() always verifies the certificate, and accepting the option would report an insecure request that never happened.',
				$name,
				$option,
			),
			'leave verification on',
		);
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
