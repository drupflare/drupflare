<?php

declare(strict_types=1);

namespace Drupal\drupflare\Search;

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;
use Solarium\Core\Client\Adapter\AdapterHelper;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
use Solarium\Core\Event\PreExecuteRequest;
use Solarium\Exception\HttpException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Serves every Solr request from the Worker's fetch capability instead of a socket.
 *
 * WHY AN EVENT AND NOT AN ADAPTER. Solarium's transport seam is `AdapterInterface::execute()`, and
 * `search_api_solr` picks its adapter in `SolrConnectorPluginBase::createClient()` -- a `protected`
 * method, so replacing it means a custom connector plugin the site has to select. The event is
 * strictly better: `Client::executeRequest()` dispatches `PreExecuteRequest` and, if a listener has
 * set a response, uses it and never calls the adapter at all. `search_api_solr` hands Drupal's own
 * dispatcher to the Solarium client, so this subscriber intercepts every request with NO module
 * changes and no configuration.
 *
 * WHAT IT REPLACES, measured 2026-08-23 against solarium 6.4.2 and search_api_solr 4.x. The default
 * connector chooses `extension_loaded('curl') ? new Curl() : new Http()`, and this build has no curl
 * extension, so it lands on `Http` -- which is `stream_context_create()` plus
 * `@file_get_contents()`. That reaches the registered https wrapper and the BODY comes back
 * correctly. Solarium then reads `$http_response_header`, which no userland stream wrapper can
 * populate, so it gets `[]` and `Response::setHeaders()` throws `No HTTP status found`. That is the
 * same wall Guzzle hit, and it is why the interception has to happen above the adapter rather than
 * inside it.
 *
 * THE FIRST QUERY CANNOT HAVE ITS ANSWER, and that is a property of the runtime rather than of this
 * class. PHP cannot await, so the capability is cached-or-deferred: a miss queues the request and
 * refuses, the drain fetches it between PHP runs, and the next identical request is served from the
 * cache. Indexing is unaffected because it is not read back inside the same run. A QUERY pays one
 * round trip the first time and nothing afterwards while the entry is fresh.
 *
 * The refusal is an `HttpException`, which is what `search_api_solr` already catches and converts to
 * a `SearchApiSolrException`, so a site sees "the backend did not answer" rather than a parse error
 * from a half-built response.
 *
 * @see \Drupal\drupflare\Http\CachedFetchHandler for the same shape behind Guzzle
 */
final class SolariumTransport implements EventSubscriberInterface
{
	/**
	 * Solarium names its events by CLASS, so this is the class name as a literal.
	 *
	 * A string rather than `Events::PRE_EXECUTE_REQUEST`: the constant would make this
	 * file load Solarium, and Solarium is a `require-dev` here. As a literal, a site without
	 * search_api_solr registers a subscriber for an event nothing ever dispatches, which costs one
	 * array entry and cannot fatal.
	 */
	public const EVENT = 'Solarium\\Core\\Event\\PreExecuteRequest';

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		return [self::EVENT => ['onPreExecuteRequest', 0]];
	}

	/**
	 * Answers a Solr request from the fetch cache, or refuses and queues it.
	 *
	 * @param PreExecuteRequest $event
	 *   The event, carrying the request and the endpoint it is bound for.
	 *
	 * @throws HttpException
	 *   When nothing is cached yet. The request is queued first, so a retry succeeds.
	 */
	public function onPreExecuteRequest(PreExecuteRequest $event): void
	{
		if (!Host::has('cfwFetch')) {
			// declining silently hands the request back to Solarium's own adapter, which on this
			// runtime dies as an uncatchable `Asyncify is not defined` and takes the invocation
			Degradation::record(
				'solarium.transport',
				'the host fetch capability is absent, so Solr traffic cannot be intercepted; Solarium own adapter cannot run here.',
			);
			return;
		}

		$request = $event->getRequest();
		$endpoint = $event->getEndpoint();

		// a file upload streams from disk and has no body to hand a fetch; declared rather than
		// silently sent empty, which would index nothing and report success
		if ($request->getFileUpload() !== null) {
			Degradation::record(
				'solarium.file_upload',
				'Solr extract/update by FILE UPLOAD needs a streaming request body, which the Worker fetch capability does not take. Index the extracted text instead of posting the file.',
			);
			throw new HttpException(
				'a Solr file upload cannot be sent through the Worker fetch capability',
			);
		}

		$uri = AdapterHelper::buildUri($request, $endpoint);
		$method = strtoupper($request->getMethod() ?? Request::METHOD_GET);
		$body = (string) ($request->getRawData() ?? '');

		$reply = Host::call('cfwFetch', [
			'url' => $uri,
			'method' => $method,
			'headers' => self::headers($request, $endpoint),
			'body' => $body,
		]);

		if (($reply['ok'] ?? false) !== true) {
			throw new HttpException(
				sprintf(
					'Solr request queued rather than answered: %s. A Worker cannot fetch synchronously, so the first request for a given query misses and the next one is served from the cache.',
					(string) ($reply['error'] ?? 'no reason given'),
				),
			);
		}

		$status = (int) ($reply['status'] ?? 200);
		$event->setResponse(
			new Response((string) ($reply['body'] ?? ''), self::responseLines($status, $reply)),
		);
	}

	/**
	 * Builds the outbound header map.
	 *
	 * Solarium gives header LINES, and the endpoint carries credentials separately, so both have to
	 * be folded into the name => value map the host takes.
	 *
	 * @param Request $request
	 *   The Solr request.
	 * @param object $endpoint
	 *   The endpoint; typed loosely because only its authentication is read.
	 *
	 * @return array
	 *   Header name to value.
	 */
	private static function headers(Request $request, object $endpoint): array
	{
		$out = [];
		foreach ($request->getHeaders() as $line) {
			$line = trim((string) $line);
			if ($line === '' || !str_contains($line, ':')) {
				continue;
			}
			[$name, $value] = explode(':', $line, 2);
			$out[trim($name)] = trim($value);
		}

		// basic auth is what the shipped Http adapter sends, so matching it keeps a configured
		// endpoint working unchanged. It reaches the wire AND the cache key, which is what stops two
		// endpoints with different credentials sharing a row
		if (method_exists($endpoint, 'getAuthentication')) {
			$auth = $endpoint->getAuthentication();
			if (is_array($auth) && ($auth['username'] ?? '') !== '') {
				$out['Authorization'] =
					'Basic ' .
					base64_encode(
						((string) $auth['username']) . ':' . ((string) ($auth['password'] ?? '')),
					);
			}
		}

		return $out;
	}

	/**
	 * Rebuilds the header LINES Solarium parses a status out of.
	 *
	 * `Response::setHeaders()` scans for the first line starting `HTTP` and does
	 * `explode(' ', $line, 3)`, reading index 2 as the message -- so the status line needs all three
	 * parts or Solarium raises on a missing index rather than on a bad status.
	 *
	 * @param int $status
	 *   The status the drain recorded.
	 * @param array $reply
	 *   The host reply, whose `headers` member is a name => value map.
	 *
	 * @return array
	 *   Header lines, status first.
	 */
	private static function responseLines(int $status, array $reply): array
	{
		$lines = [sprintf('HTTP/1.1 %d %s', $status, self::reason($status))];
		$headers = $reply['headers'] ?? [];
		if (is_array($headers)) {
			foreach ($headers as $name => $value) {
				if (is_array($value) || is_object($value)) {
					continue;
				}
				$lines[] = sprintf('%s: %s', (string) $name, (string) $value);
			}
		}
		return $lines;
	}

	/**
	 * A reason phrase for a status code.
	 *
	 * The value is never read for meaning -- Solarium only needs a third word -- so the common codes
	 * are named and everything else gets a generic phrase rather than a lookup table nobody reads.
	 *
	 * @param int $status
	 *   The status code.
	 *
	 * @return string
	 *   A non-empty reason phrase.
	 */
	private static function reason(int $status): string
	{
		return match ($status) {
			200 => 'OK',
			201 => 'Created',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			500 => 'Internal Server Error',
			503 => 'Service Unavailable',
			default => $status < 400 ? 'OK' : 'Error',
		};
	}
}
