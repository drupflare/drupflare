<?php

declare(strict_types=1);

namespace Drupal\drupflare\Http;

use Drupal\drupflare\Host;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * A Guzzle transport that answers inside the request, by parking the PHP call.
 *
 * The third of three, and the runtime decides which one a site gets.
 * {@see FetchHandler} awaits the host directly and needs Asyncify or JSPI, which this build has
 * neither of. {@see CachedFetchHandler} queues the call and answers it on a later invocation, which
 * is correct for anything retryable and useless for an exchange whose answer has to arrive before
 * the response is written. This one suspends the PHP call itself: the Worker performs the fetch
 * while PHP is frozen mid-call, then resumes it with the reply.
 *
 * The yield itself is {@see Park::yieldTo()}, which explains why a socket function carries it.
 *
 * **WHY A HANDLER AND NOT THE STREAM WRAPPER.** Guzzle's own `StreamHandler` reaches HTTPS through
 * `fopen()`, and a park under an INTERNAL frame is refused by design -- `fopen`'s C locals cannot
 * survive the `longjmp`, so `park_refused()` declines it and the call falls through. This class is
 * plain userland invoked from Guzzle's stack, which is a frame the park can suspend under. That is
 * the whole reason the transport is replaced here rather than intercepted lower down.
 *
 * **A REFUSED PARK FALLS BACK TO THE DEFERRED TRANSPORT rather than failing**, which is the same
 * additive rule the extension itself follows. Rejecting instead made this transport strictly worse
 * than the one it replaced: every Search.gov render answered 500. The park declines under an
 * internal frame that owns C state -- `array_map`, `usort`, a callback inside `fopen` -- and Drupal's
 * own dispatch is no longer one of those: `park_flatten()` splices a `call_user_func_array` frame out
 * of the chain instead. So a refusal is now the exception rather than the common case, and
 * {@link Park::refusal()} names the frame when it happens.
 *
 * A transport error still comes back as a rejected promise rather than an exception, because that is
 * what a module expects: `drupal/openid_connect` catches a Guzzle exception and reports a failed
 * login, where an uncaught throw would take the render with it.
 */
final class ParkFetchHandler
{
	/**
	 * The scheme the host dispatches on; must match `PARK_FETCH_SCHEME` in `park-drive.ts`.
	 */
	public const SCHEME = 'cfwpark+fetch://';

	/**
	 * Whether this runtime can park a call and have the host answer it.
	 *
	 * BOTH HALVES, and neither alone is enough. `cfw_park_run` says the interpreter can suspend;
	 * `cfwParkFetch` says the host installed the loop that answers one. Arming on the first alone
	 * would park every outbound call on a deployment whose Worker never answers it, which is a hang
	 * rather than a degradation.
	 */
	public static function available(): bool
	{
		return function_exists('cfw_park_run') && Host::hasParkFetch();
	}

	/**
	 * The transport a refused park falls back to.
	 *
	 * Built lazily, so a site whose parks all take never constructs one.
	 */
	private ?CachedFetchHandler $fallback = null;

	/**
	 * The deferred transport, for a request the park declined.
	 *
	 * @return CachedFetchHandler
	 *   The fallback, constructed on first use.
	 */
	private function deferred(): CachedFetchHandler
	{
		return $this->fallback ??= new CachedFetchHandler();
	}

	/**
	 * Performs one request by suspending PHP until the Worker has the answer.
	 *
	 * @param RequestInterface $request
	 *   The PSR-7 request.
	 * @param array $options
	 *   Guzzle transfer options.
	 *
	 * @return PromiseInterface
	 *   Resolves with a PSR-7 response, or rejects with the transport error.
	 */
	public function __invoke(RequestInterface $request, array $options): PromiseInterface
	{
		try {
			$headers = [];
			foreach ($request->getHeaders() as $name => $values) {
				$headers[$name] = implode(', ', $values);
			}

			$body = (string) $request->getBody();
			$descriptor = [
				'method' => $request->getMethod(),
				'url' => (string) $request->getUri(),
				'headers' => $headers,
				// base64 because a request body is bytes, and this rides through JSON
				'body' => $body === '' ? '' : base64_encode($body),
				// Cloudflare follows redirects by default; Guzzle expects to control it
				'redirect' => empty($options['allow_redirects']) ? 'manual' : 'follow',
			];

			$json = json_encode($descriptor);
			if ($json === false) {
				return new RejectedPromise(
					new RuntimeException('the request could not be encoded for the park'),
				);
			}

			// the park: answers a JSON string rather than a stream, because of the scheme
			$raw = Park::yieldTo(self::SCHEME . base64_encode($json));
			if (!is_string($raw)) {
				// REFUSED, AND THAT IS NOT A FAILURE. Falling back keeps the failure mode ADDITIVE,
				// which is the same rule the extension follows when it calls the real function
				// instead of parking. Rejecting instead made this transport strictly worse than the
				// deferred one it replaced: every Search.gov render answered 500 until this existed.
				//
				// The frames are recorded rather than logged, because a refusal is per REQUEST and a
				// watchdog row per request is a meter this project counts. `/__serve-stats` reads it.
				$GLOBALS['CFW_PARK_REFUSAL'] = Park::refusal() + [
					'url' => (string) $request->getUri(),
				];
				return $this->deferred()($request, $options);
			}

			$reply = json_decode($raw, true);
			if (!is_array($reply)) {
				return new RejectedPromise(new RuntimeException('the park answer was not JSON'));
			}
			if (isset($reply['error']) && $reply['error'] !== '') {
				return new RejectedPromise(new RuntimeException((string) $reply['error']));
			}

			$responseHeaders = [];
			foreach ((array) ($reply['headers'] ?? []) as $name => $value) {
				$responseHeaders[(string) $name] = (string) $value;
			}
			$decoded = base64_decode((string) ($reply['body'] ?? ''), true);

			return new FulfilledPromise(
				new Response(
					(int) ($reply['status'] ?? 0),
					$responseHeaders,
					$decoded === false ? '' : $decoded,
				),
			);
		} catch (Throwable $e) {
			return new RejectedPromise($e);
		}
	}
}
