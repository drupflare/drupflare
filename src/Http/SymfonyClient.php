<?php

declare(strict_types=1);

namespace Drupal\drupflare\Http;

use Closure;
use Drupal\drupflare\DrupflareServiceProvider;
use GuzzleHttp\Psr7\Request;
use Throwable;

/**
 * A Symfony `HttpClientInterface` over the transport Drupal::httpClient() uses.
 *
 * `HttpClient::create()` falls back to `NativeHttpClient` when ext-curl is absent, and that client
 * reads response metadata only PHP's own http wrapper produces, so every request through it fails.
 * A project that ships symfony/http-client gets `create()` rewritten to call {@see self::create()}.
 *
 * The client is Symfony's own callback client (`MockHttpClient`), which already normalises every
 * request option, so this class only performs the exchange: one request through the park where the
 * runtime can park, or the deferred queue where it cannot. Responses are buffered; a streamed body
 * such as server-sent events arrives whole when the exchange completes.
 *
 * Class names are strings because symfony/http-client is a dependency of the project, not of this
 * module.
 */
final class SymfonyClient
{
	/**
	 * The client, or NULL when symfony/http-client is not installed.
	 *
	 * @param array $defaultOptions
	 *   What `HttpClient::create()` was given.
	 * @param callable|null $handler
	 *   A Guzzle-shaped handler; NULL for the one this runtime installs.
	 */
	public static function create(array $defaultOptions = [], ?callable $handler = null): ?object
	{
		$client = 'Symfony\Component\HttpClient\MockHttpClient';
		$response = 'Symfony\Component\HttpClient\Response\MockResponse';
		if (!class_exists($client) || !class_exists($response)) {
			return null;
		}
		if ($handler === null) {
			$class = DrupflareServiceProvider::pickHandlerClass();
			$handler = new $class();
		}
		$factory = static function (string $method, string $url, array $options) use (
			$handler,
			$response,
		): object {
			$body = $options['body'] ?? '';
			if ($body instanceof Closure) {
				$buffered = '';
				while (($chunk = $body(16372)) !== '') {
					$buffered .= $chunk;
				}
				$body = $buffered;
			}
			$headers = [];
			foreach ($options['headers'] ?? [] as $line) {
				$parts = explode(':', (string) $line, 2);
				if (count($parts) === 2) {
					$headers[trim($parts[0])][] = trim($parts[1]);
				}
			}
			$transfer = [];
			$timeout =
				(float) ($options['max_duration'] ?? 0) ?: (float) ($options['timeout'] ?? 0);
			if ($timeout > 0) {
				$transfer['timeout'] = $timeout;
			}
			if (($options['max_redirects'] ?? 0) > 0) {
				$transfer['allow_redirects'] = true;
			}
			try {
				$answer = $handler(
					new Request($method, $url, $headers, is_string($body) ? $body : ''),
					$transfer,
				)->wait();
			} catch (Throwable $e) {
				// MockResponse turns this into the TransportException a Symfony caller catches
				return new $response('', ['error' => $e->getMessage()]);
			}
			$responseHeaders = [];
			foreach ($answer->getHeaders() as $name => $values) {
				foreach ($values as $value) {
					$responseHeaders[] = $name . ': ' . $value;
				}
			}
			return new $response((string) $answer->getBody(), [
				'http_code' => $answer->getStatusCode(),
				'response_headers' => $responseHeaders,
			]);
		};
		$made = new $client($factory, null);
		return $defaultOptions === [] ? $made : $made->withOptions($defaultOptions);
	}
}
