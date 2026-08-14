<?php

declare(strict_types=1);

namespace Drupal\drupflare\Http;

use Drupal\drupflare\DrupflareServiceProvider;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Guzzle handler backed by the Worker runtime's fetch().
 *
 * A Worker has no sockets, so PHP has neither curl nor an http/https stream
 * wrapper: measured, the only wrappers present are compress.zlib, php, file,
 * glob and data. Guzzle's Utils::chooseHandler() therefore sees allow_url_fopen
 * and silently selects StreamHandler, which then fails on every request with
 * "Unable to find the wrapper https" -- late, and per request.
 *
 * Replacing the handler routes Drupal::httpClient() through the platform's own
 * fetch() instead, which inherits Cloudflare's connection pooling, egress and
 * TLS termination rather than shipping a TLS stack in the binary.
 *
 * STATUS: requires a suspension mechanism. vrzno_await() is compiled against
 * Asyncify, and the current build sets ASYNCIFY=0 to save 42% of the bundle:
 *
 *   ReferenceError: Asyncify is not defined at __asyncjs__vrzno_await_internal
 *
 * workerd does expose JSPI (WebAssembly.Suspending / promising / SuspendError
 * all probe true), which provides the same semantics without Asyncify's code
 * bloat. This handler is written against that build and is NOT yet exercised.
 * Do not enable it on an ASYNCIFY=0, non-JSPI runtime.
 *
 * @see DrupflareServiceProvider
 */
final class FetchHandler
{
	/**
	 * The JS host object exposing fetch(), surfaced via vrzno.
	 */
	private object $host;

	public function __construct(?object $host = null)
	{
		// vrzno_env($name) resolves to Module[$name]; the runtime hangs its host
		// helpers there when constructing PHP.
		$this->host = $host ?? vrzno_env('cfHost');
	}

	/**
	 * Guzzle calls the handler as a callable.
	 *
	 * @param RequestInterface $request
	 *   The outbound request.
	 * @param array $options
	 *   Guzzle transfer options.
	 *
	 * @return PromiseInterface
	 *   Resolves with a PSR-7 response.
	 */
	public function __invoke(RequestInterface $request, array $options): PromiseInterface
	{
		try {
			$headers = [];
			foreach ($request->getHeaders() as $name => $values) {
				$headers[$name] = implode(', ', $values);
			}

			$body = (string) $request->getBody();

			// Timeouts are the runtime's concern, but pass Guzzle's through so
			// behaviour is not silently different from other transports.
			$init = [
				'method' => $request->getMethod(),
				'headers' => $headers,
				'body' => $body === '' ? null : $body,
				// Cloudflare follows redirects by default; Guzzle expects to control it
				'redirect' => empty($options['allow_redirects']) ? 'manual' : 'follow',
			];

			// The host returns a plain object rather than a live Response, because
			// every field has to be read on the JS side while the promise is still
			// resolvable.
			$result = vrzno_await($this->host->fetch((string) $request->getUri(), $init));

			if (!is_object($result)) {
				return new RejectedPromise(
					new RuntimeException('fetch bridge returned ' . gettype($result)),
				);
			}

			$status = (int) $result->status;
			$respHeaders = [];
			foreach ((array) $result->headers as $k => $v) {
				$respHeaders[$k] = $v;
			}

			return new FulfilledPromise(
				new Response($status, $respHeaders, (string) $result->body),
			);
		} catch (Throwable $e) {
			return new RejectedPromise($e);
		}
	}
}
