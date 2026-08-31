<?php

/**
 * @file
 * Drives SolariumTransport against real Solarium objects.
 *
 * SEPARATE FROM health-suite.php, and not because it is a second file for one feature.
 * These assertions need `vrzno_env()` to exist so `Host::call()` reaches a double, and defining that
 * global in the health suite would change what every other Host-touching assertion there measures.
 * Isolated: the domain is search transport and this is its only file.
 *
 * SKIPS WHEN SOLARIUM IS ABSENT, loudly. The worker's gate checks this repo out with no
 * `composer install`, so a hard failure there would be reporting the absence of a dev dependency as
 * a defect. It says which it did rather than exiting 0 either way.
 */

$root = __DIR__ . '/..';

// the double has to be installed BEFORE anything calls Host::fn(), which resolves through it
$GLOBALS['__cfw_host'] = [];
if (!function_exists('vrzno_env')) {
	/**
	 * Stands in for the runtime's capability table.
	 *
	 * @param string $name
	 *   The capability key.
	 *
	 * @return mixed
	 *   The registered double, or NULL when nothing is registered.
	 */
	function vrzno_env(string $name): mixed
	{
		return $GLOBALS['__cfw_host'][$name] ?? null;
	}
}

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Search\SolariumTransport;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Event\PreExecuteRequest;
use Solarium\Exception\HttpException;

require_once $root . '/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($root): void {
	$prefix = 'Drupal\\drupflare\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$rel = str_replace('\\', '/', substr($class, strlen($prefix)));
	$file = $root . '/src/' . $rel . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});

// `use` is compile-time and file-scoped, so the alias resolves here even though the guard runs
// before the class could be loaded; `::class` on an alias never triggers autoloading
if (!class_exists(PreExecuteRequest::class)) {
	echo "SKIPPED: solarium is not installed in this checkout, so the transport cannot be driven.\n";
	echo "         run `composer install` here to include these assertions.\n";
	exit(0);
}

$pass = 0;
$fail = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $label
 *   What the assertion claims.
 * @param bool $condition
 *   The result of evaluating that claim.
 * @param string $detail
 *   Extra context printed only on failure.
 */
function ok(string $label, bool $condition, string $detail = ''): void
{
	global $pass, $fail;
	if ($condition) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
	}
}

/**
 * Registers what `cfwFetch` answers, and records what it was asked.
 *
 * @param array $reply
 *   The reply to hand back.
 * @param array|null $seen
 *   Receives the decoded request, so an assertion can read what crossed.
 */
function host_answers(array $reply, ?array &$seen = null): void
{
	$seen = null;
	$GLOBALS['__cfw_host']['cfwFetch'] = function (string $json) use ($reply, &$seen): string {
		$seen = json_decode($json, true);
		return json_encode($reply);
	};
}

/**
 * Builds an event for a query against a local endpoint.
 *
 * @param array $options
 *   Endpoint options to override.
 * @param string|null $rawData
 *   A request body, for the POST case.
 *
 * @return PreExecuteRequest
 *   The event, ready to hand to the subscriber.
 */
function event_for(array $options = [], ?string $rawData = null): PreExecuteRequest
{
	$request = new Request();
	$request->setHandler('select');
	$request->addParam('q', '*:*');
	if ($rawData !== null) {
		$request->setMethod(Request::METHOD_POST);
		$request->setRawData($rawData);
	}
	$endpoint = new Endpoint(
		$options + ['host' => '127.0.0.1', 'port' => 8983, 'path' => '/', 'core' => 'drupal'],
	);
	return new PreExecuteRequest($request, $endpoint);
}

$transport = new SolariumTransport();

// #region the seam itself
//
// The constant is a LITERAL so this file does not load Solarium at parse time. That makes it drift
// silently if Solarium ever renames the event, which is exactly what this assertion is for.
ok(
	'the subscribed event name is the real Solarium class',
	SolariumTransport::EVENT === PreExecuteRequest::class,
	SolariumTransport::EVENT,
);
ok(
	'and it is registered as a subscriber for it',
	array_key_exists(SolariumTransport::EVENT, SolariumTransport::getSubscribedEvents()),
);
// #endregion

// #region a cache hit answers without the adapter ever running
host_answers(
	[
		'ok' => true,
		'status' => 200,
		'headers' => ['content-type' => 'application/json'],
		'body' => '{"response":{"numFound":3}}',
	],
	$seen,
);
$event = event_for();
$transport->onPreExecuteRequest($event);
$response = $event->getResponse();

ok('a cache hit sets a response, which short-circuits the adapter', $response !== null);
ok('with the status the drain recorded', $response?->getStatusCode() === 200);
ok('and the body verbatim', $response?->getBody() === '{"response":{"numFound":3}}');
// Response::setHeaders() does explode(' ', $line, 3) and reads index 2, so a two-word status line
// raises on a missing index rather than reporting a bad status
ok('the status line carries a reason phrase', $response?->getStatusMessage() === 'OK');
ok(
	'the request went to the endpoint url',
	is_string($seen['url'] ?? null) && str_contains((string) $seen['url'], '127.0.0.1:8983'),
	(string) ($seen['url'] ?? 'no url'),
);
ok('and named the handler', str_contains((string) ($seen['url'] ?? ''), 'select'));
// #endregion

// #region a miss refuses in the vocabulary search_api_solr already catches
host_answers(['ok' => false, 'error' => 'not in the fetch cache; queued', 'queued' => true]);
$missed = event_for();
$threw = false;
$why = '';
try {
	$transport->onPreExecuteRequest($missed);
} catch (HttpException $e) {
	$threw = true;
	$why = $e->getMessage();
}
ok('a miss throws HttpException rather than half-building a response', $threw);
ok(
	'the refusal names the queue rather than looking like a network error',
	str_contains($why, 'queued'),
);
ok('and no response was set', $missed->getResponse() === null);
// #endregion

// #region credentials reach the wire, which is what P46 made possible
host_answers(['ok' => true, 'status' => 200, 'headers' => [], 'body' => '{}'], $seen);
$transport->onPreExecuteRequest(event_for(['username' => 'solr', 'password' => 'SolrRocks']));
$sentAuth = $seen['headers']['Authorization'] ?? '';
ok(
	'basic auth from the endpoint is sent',
	$sentAuth === 'Basic ' . base64_encode('solr:SolrRocks'),
	$sentAuth,
);
// #endregion

// #region a POST carries its body
host_answers(['ok' => true, 'status' => 200, 'headers' => [], 'body' => '{}'], $seen);
$transport->onPreExecuteRequest(event_for([], '<add><doc/></add>'));
ok('a POST sends its raw data', ($seen['body'] ?? '') === '<add><doc/></add>');
ok('with the method it declared', ($seen['method'] ?? '') === 'POST');
// #endregion

// #region a file upload is DECLARED, never silently sent empty
Degradation::reset();
$upload = new Request();
$upload->setHandler('update/extract');
$upload->setMethod(Request::METHOD_POST);
$upload->setFileUpload(__FILE__);
$uploadEvent = new PreExecuteRequest(
	$upload,
	new Endpoint(['host' => '127.0.0.1', 'port' => 8983, 'path' => '/', 'core' => 'drupal']),
);
$uploadThrew = false;
try {
	$transport->onPreExecuteRequest($uploadEvent);
} catch (HttpException $e) {
	$uploadThrew = true;
}
ok('a file upload refuses rather than posting an empty body', $uploadThrew);
ok(
	'and declares the gap so it surfaces in the status report',
	Degradation::isDeclared('solarium.file_upload'),
);
// #endregion

// #region no capability means no interception, so a build without the host is untouched
$GLOBALS['__cfw_host'] = [];
$untouched = event_for();
$transport->onPreExecuteRequest($untouched);
ok(
	'with no cfwFetch the event is left alone for the real adapter',
	$untouched->getResponse() === null,
);
// #endregion

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
