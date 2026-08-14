<?php

declare(strict_types=1);

namespace Drupal\drupflare\Queue;

use Drupal\drupflare\Host;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle handler that defers a request instead of waiting for it.
 *
 * The audit behind this: ten core files touch the HTTP client, and NONE of them
 * needs a synchronous response on the anonymous request path. Six are cron or admin
 * (update checks, security advisories, the announcements feed, locale files,
 * migrate); four are oEmbed, which is cacheable after first resolution.
 *
 * That matters because a synchronous outbound fetch needs JSPI to suspend the
 * interpreter, and this build has no suspension mechanism. Deferring needs none: the
 * request is handed to a queue and the caller gets an immediate 202. So the common
 * case works TODAY, and JSPI becomes an optimisation for the genuinely synchronous
 * remainder rather than a precondition for outbound HTTP at all.
 *
 * Layering, deliberately in this order:
 *   - cached   -> a previous fetch's response, no suspension
 *   - deferred -> queued, 202, no suspension          <- this class
 *   - sync     -> JSPI suspend, when a build has it
 *
 * A caller that genuinely cannot proceed without a body gets a 202 and must handle
 * it. That is a real behaviour change from a blocking client, and it is why this is
 * a handler a site opts into per service rather than a global default.
 */
class CfwDeferredHttp
{
	/**
	 * Methods that are safe to answer from cache before deferring.
	 */
	private const CACHEABLE = ['GET', 'HEAD'];

	/**
	 * Handles one request.
	 *
	 * @param RequestInterface $request
	 *   The request.
	 * @param array $options
	 *   Guzzle options. 'cfw_deferred' => FALSE forces a synchronous attempt.
	 *
	 * @return PromiseInterface
	 *   Always fulfilled: either a cached response, a live one, or a 202.
	 */
	public function __invoke(RequestInterface $request, array $options): PromiseInterface
	{
		$method = strtoupper($request->getMethod());
		$url = (string) $request->getUri();

		// 1. cached, if a previous fetch left a body behind
		if (in_array($method, self::CACHEABLE, true)) {
			$cached = Host::call('cfwHttpCacheGet', ['url' => $url]);
			if (($cached['ok'] ?? false) === true && isset($cached['body'])) {
				return new FulfilledPromise(
					new Response(
						(int) ($cached['status'] ?? 200),
						self::headers($cached['headers'] ?? []),
						(string) $cached['body'],
					),
				);
			}
		}

		// 2. synchronous, only when the runtime can suspend and the caller asked
		if (($options['cfw_sync'] ?? false) === true && Host::has('cfwFetchSync')) {
			$live = Host::call('cfwFetchSync', [
				'url' => $url,
				'method' => $method,
				'headers' => self::flatten($request->getHeaders()),
				'body' => (string) $request->getBody(),
			]);
			if (($live['ok'] ?? false) === true) {
				return new FulfilledPromise(
					new Response(
						(int) ($live['status'] ?? 200),
						self::headers($live['headers'] ?? []),
						(string) ($live['body'] ?? ''),
					),
				);
			}
		}

		// 3. deferred: hand it to the queue and answer immediately
		$queued = Host::call('cfwQueueFetch', [
			'url' => $url,
			'method' => $method,
			'headers' => self::flatten($request->getHeaders()),
			'body' => (string) $request->getBody(),
		]);

		$ok = ($queued['ok'] ?? false) === true;
		return new FulfilledPromise(
			new Response(
				$ok ? 202 : 503,
				[
					'content-type' => 'application/json',
					'x-cfw-deferred' => $ok ? 'queued' : 'failed',
				],
				json_encode([
					'deferred' => $ok,
					'url' => $url,
					'error' => $ok ? null : $queued['error'] ?? 'unknown',
					'note' =>
						'Workers have no sockets; this request was queued rather than awaited. See CfwDeferredHttp.',
				]) ?:
				'{}',
			),
		);
	}

	/**
	 * Guzzle gives header values as lists; the host takes one string per name.
	 */
	private static function flatten(array $headers): array
	{
		$out = [];
		foreach ($headers as $name => $values) {
			$out[(string) $name] = is_array($values) ? implode(', ', $values) : (string) $values;
		}
		return $out;
	}

	/**
	 * And the reverse, because PSR-7 wants lists back.
	 */
	private static function headers(mixed $headers): array
	{
		if (!is_array($headers)) {
			return [];
		}
		$out = [];
		foreach ($headers as $name => $value) {
			$out[(string) $name] = [is_array($value) ? implode(', ', $value) : (string) $value];
		}
		return $out;
	}
}
