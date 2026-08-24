<?php

declare(strict_types=1);

namespace Drupal\drupflare\Http;

use Drupal\drupflare\DrupflareServiceProvider;
use Drupal\drupflare\Queue\CfwDeferredHttp;
use Drupal\drupflare\StreamWrapper\HttpsStreamWrapper;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * The Guzzle handler for a build that cannot suspend: answer from the fetch cache, or refuse.
 *
 * WHAT THIS REPLACES IS A HANDLER THAT COULD NEVER RETURN A RESPONSE. Core's `StreamHandler`
 * opens the URL through the https stream wrapper this module registers -- which works, and returns
 * a live resource -- and then reads `$http_response_header`, a magic local only PHP's OWN http
 * wrapper populates. A userland wrapper cannot set it, and PHP 8.4's replacement
 * `http_get_last_response_headers()` answers NULL for the same reason, so `lastHeaders` is empty,
 * `HeaderProcessor::parseHeaders([])` throws, and `createResponse()` rejects with
 * `RequestException: An error was encountered while creating the response`. The fetch SUCCEEDED and
 * its result was discarded. Measured on `/admin/reports/status`, and it failed the same way for
 * every one of the ten core seams that reach `Drupal::httpClient()`.
 *
 * The stream wrapper is not the problem and is not changed: `file_get_contents('https://...')`
 * reads its body correctly, because that path never asks for headers.
 *
 * TWO OUTCOMES, both of which a caller already handles:
 *
 *   - the response a previous drain fetched, as a real PSR-7 response;
 *   - `ConnectException`, after arming the queue so the NEXT call hits.
 *
 * `ConnectException` rather than a 202, and the choice matters. `CfwDeferredHttp` answers 202 with
 * a JSON body explaining the deferral, which is right for a caller that opted in per service and
 * wrong as a global default: Guzzle's `http_errors` middleware does not raise on a 2xx, so every
 * caller would decode the explanation as if it were the payload. `SecurityAdvisoriesFetcher` would
 * `Json::decode()` it and iterate four scalars. A refusal is what a caller's existing error path is
 * already written for, and it is what core's own handler raises when a socket cannot be opened.
 *
 * REQUEST HEADERS ARE CARRIED, since 2026-08-23. They used to reach neither the cache key nor the
 * eventual `fetch()`, so an API client sending `Authorization` got an unauthenticated 401 back as a
 * real response. `cfwFetch` now keys on method + URL + body + headers and the queue carries them.
 *
 * The key includes them because omitting them is a DISCLOSURE, not a saving: two callers asking one
 * URL with different credentials would otherwise share a row, and the second would be served the
 * first one's authenticated response.
 *
 * TWO SETS, and the wire set is the larger one. `Host`, `Content-Length`, `Connection`,
 * `Transfer-Encoding`, `Keep-Alive` and `Upgrade` reach neither, because `fetch()` computes them.
 * `User-Agent` is SENT but not keyed, which is what keeps the sentence below true: Guzzle's
 * StreamHandler puts its own `User-Agent` in the stream context and a bare `file_get_contents()`
 * sends none, so keying on it would split one URL into a row per client library.
 *
 * @see CfwDeferredHttp for the 202 form, which a site opts into per service
 * @see FetchHandler for the JSPI form, which suspends and needs no cache
 * @see DrupflareServiceProvider for which one is installed
 */
final class CachedFetchHandler
{
	/**
	 * Handles one request.
	 *
	 * @param RequestInterface $request
	 *   The outbound request.
	 * @param array $options
	 *   Guzzle transfer options; none is read, and none can be honoured by a cache read.
	 *
	 * @return PromiseInterface
	 *   Fulfilled with the cached response, or rejected with a ConnectException.
	 */
	public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
	{
		$url = (string) $request->getUri();
		$method = strtoupper($request->getMethod());

		try {
			// the same capability the stream wrapper opens through, so a hit here and a successful
			// file_get_contents() are the same row rather than two caches that can disagree
			$reply = HttpsStreamWrapper::hostFetch()([
				'url' => $url,
				'method' => $method,
				'headers' => self::flatten($request->getHeaders()),
				'body' => (string) $request->getBody(),
			]);
		} catch (Throwable $e) {
			return new RejectedPromise(
				new ConnectException(
					sprintf('%s: %s', get_class($e), $e->getMessage()),
					$request,
					$e,
				),
			);
		}

		if (!is_array($reply) || ($reply['ok'] ?? false) !== true) {
			$why = is_array($reply)
				? (string) ($reply['error'] ?? 'no reason given')
				: 'the host returned ' . get_debug_type($reply);
			return new RejectedPromise(new ConnectException($why, $request));
		}

		return new FulfilledPromise(
			new Response(
				(int) ($reply['status'] ?? 200),
				self::headers($reply['headers'] ?? []),
				(string) ($reply['body'] ?? ''),
			),
		);
	}

	/**
	 * The host sends one string per name; PSR-7 wants a list per name.
	 *
	 * @param mixed $headers
	 *   The reply's headers member, which a refusing host may not send at all.
	 *
	 * @return array
	 *   Header name to a single-element list.
	 */
	private static function headers(mixed $headers): array
	{
		if (!is_array($headers)) {
			return [];
		}
		$out = [];
		foreach ($headers as $name => $value) {
			if (is_array($value) || is_object($value)) {
				continue;
			}
			$out[(string) $name] = [(string) $value];
		}
		return $out;
	}

	/**
	 * And the other direction: Guzzle gives a list per name, the host takes one string.
	 *
	 * @param array $headers
	 *   What getHeaders() returned.
	 *
	 * @return array
	 *   Header name to a single comma-joined value, which is how HTTP folds a repeat anyway.
	 */
	private static function flatten(array $headers): array
	{
		$out = [];
		foreach ($headers as $name => $values) {
			$out[(string) $name] = is_array($values) ? implode(', ', $values) : (string) $values;
		}
		return $out;
	}
}
