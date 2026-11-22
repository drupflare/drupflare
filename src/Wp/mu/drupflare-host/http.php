<?php

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;
use Drupal\drupflare\Http\Park;

// the curl stand-in reports curl_version() with no features but defines no CURL_VERSION_*
// constants, and the transport capability test reads one
if (!defined('CURL_VERSION_SSL')) {
	define('CURL_VERSION_SSL', 4);
}

const DRUPFLARE_HTTP_SCHEME = 'cfwpark+fetch://';
const DRUPFLARE_HTTP_DEFAULT_BUDGET = 30;
const DRUPFLARE_HTTP_DEFAULT_MAX_BYTES = 5242880;
const DRUPFLARE_HTTP_DEFAULT_TIMEOUT_MS = 5000;
const DRUPFLARE_HTTP_MAX_TIMEOUT_MS = 30000;

/**
 * What this request has spent on outbound requests.
 *
 * @return array{spent: int, refused: int, calls: array<int, array{host: string, status: int|string}>}
 */
function &drupflare_http_state(): array
{
	static $state = ['spent' => 0, 'refused' => 0, 'calls' => []];
	return $state;
}

function drupflare_http_note_refusal(): void
{
	$state = &drupflare_http_state();
	$state['refused']++;
}

function drupflare_http_budget(): int
{
	return defined('DRUPFLARE_HTTP_BUDGET')
		? max(1, (int) DRUPFLARE_HTTP_BUDGET)
		: DRUPFLARE_HTTP_DEFAULT_BUDGET;
}

function drupflare_http_max_bytes(): int
{
	return defined('DRUPFLARE_HTTP_MAX_BYTES')
		? max(1, (int) DRUPFLARE_HTTP_MAX_BYTES)
		: DRUPFLARE_HTTP_DEFAULT_MAX_BYTES;
}

/** whether the host answers a parked fetch, which is the only way out */
function drupflare_http_available(): bool
{
	return Park::available() && Host::hasParkFetch();
}

#region the address policy, kept in step with refuseOutbound() in the worker

function drupflare_http_blocked_ipv4(string $host): ?string
{
	$parts = explode('.', $host);
	if (count($parts) !== 4) {
		return null;
	}
	foreach ($parts as $part) {
		if (!preg_match('/^\d{1,3}$/', $part) || (int) $part > 255) {
			return null;
		}
	}
	$a = (int) $parts[0];
	$b = (int) $parts[1];
	return match (true) {
		$a === 127 => 'loopback',
		$a === 10 => 'private (10/8)',
		$a === 0 => 'this network (0/8)',
		$a === 172 && $b >= 16 && $b <= 31 => 'private (172.16/12)',
		$a === 192 && $b === 168 => 'private (192.168/16)',
		$a === 169 && $b === 254 => 'link-local, which is where cloud metadata lives',
		$a === 100 && $b >= 64 && $b <= 127 => 'carrier-grade NAT (100.64/10)',
		$a >= 224 => 'multicast or reserved',
		default => null,
	};
}

function drupflare_http_blocked_ipv6(string $literal): ?string
{
	$bin = @inet_pton($literal);
	if ($bin === false || strlen($bin) !== 16) {
		return 'not a valid IPv6 address';
	}
	$hex = bin2hex($bin);
	$b0 = ord($bin[0]);
	$b1 = ord($bin[1]);
	if (str_starts_with($hex, str_repeat('0', 24))) {
		return $hex === str_repeat('0', 31) . '1' ? 'loopback' : 'unspecified or IPv4-compatible';
	}
	if (($b0 & 0xfe) === 0xfc) {
		return 'unique-local (fc00::/7)';
	}
	if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) {
		return 'link-local (fe80::/10)';
	}
	if ($b0 === 0xfe && ($b1 & 0xc0) === 0xc0) {
		return 'site-local (fec0::/10)';
	}
	if ($b0 === 0xff) {
		return 'multicast (ff00::/8)';
	}
	if (str_starts_with($hex, '0064ff9b' . str_repeat('0', 16))) {
		return 'NAT64 (64:ff9b::/96)';
	}
	if (str_starts_with($hex, '0064ff9b0001')) {
		return 'NAT64 local-use (64:ff9b:1::/48)';
	}
	if (str_starts_with($hex, str_repeat('0', 20) . 'ffff')) {
		return drupflare_http_blocked_ipv4(
			implode('.', array_map('ord', str_split(substr($bin, 12)))),
		);
	}
	return null;
}

/**
 * Why a URL may not be fetched, or null when it may.
 *
 * The worker repeats the check next to the fetch and on every redirect hop; this one gives the
 * caller a message before a request is spent.
 */
function drupflare_http_refusal(string $url): ?string
{
	$url = trim($url);
	if ($url === '') {
		return 'no url';
	}
	$parts = parse_url($url);
	if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
		return 'not a url';
	}
	$scheme = strtolower($parts['scheme']);
	if ($scheme !== 'http' && $scheme !== 'https') {
		return $scheme . ': is not an allowed scheme';
	}
	if (isset($parts['user']) || isset($parts['pass'])) {
		return 'the url carries credentials';
	}
	$host = strtolower(rtrim($parts['host'], '.'));
	if ($host === '') {
		return 'the url names no host';
	}
	if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
		$reason = drupflare_http_blocked_ipv6(substr($host, 1, -1));
		return $reason === null ? null : $host . ' is ' . $reason;
	}
	if (in_array($host, ['localhost', 'metadata.google.internal', 'metadata'], true)) {
		return $host . ' names this machine';
	}
	foreach (['.local', '.internal', '.localhost', '.home.arpa'] as $suffix) {
		if (str_ends_with($host, $suffix)) {
			return $suffix . ' is not a public suffix';
		}
	}
	$labels = explode('.', $host);
	$numeric = true;
	foreach ($labels as $label) {
		if (!preg_match('/^(0x[0-9a-f]+|\d+)$/', $label)) {
			$numeric = false;
		}
	}
	if ($numeric) {
		// a parser reads 0x7f.1 or 2130706433 as an address, so only the plain dotted form is judged
		$reason = null;
		if (count($labels) === 4 && !preg_grep('/^(0x|0\d)/', $labels)) {
			$reason = drupflare_http_blocked_ipv4($host);
			return $reason === null ? null : $host . ' is ' . $reason;
		}
		return $host . ' is a numeric host in a form this policy does not read as an address';
	}
	return null;
}

#endregion

/** wordpress.org hosts are always fetched over https, which the capability test cannot say */
function drupflare_http_upgrade_url(string $url): string
{
	$parts = parse_url($url);
	if (
		is_array($parts) &&
		strtolower($parts['scheme'] ?? '') === 'http' &&
		preg_match('/(^|\.)(wordpress\.org|w\.org)$/i', $parts['host'] ?? '') === 1
	) {
		return 'https' . substr($url, 4);
	}
	return $url;
}

/**
 * @param array<string, mixed> $args
 *
 * @return array<string, string>
 */
function drupflare_http_request_headers(array $args): array
{
	$given = $args['headers'] ?? [];
	if (!is_array($given)) {
		$given = WP_Http::processHeaders((string) $given)['headers'];
	}
	$headers = [];
	foreach ($given as $name => $value) {
		$name = (string) $name;
		$lower = strtolower($name);
		// the runtime negotiates and decodes encodings itself, and sets these two
		if (
			in_array(
				$lower,
				['accept-encoding', 'host', 'content-length', 'connection', 'expect'],
				true,
			)
		) {
			continue;
		}
		$headers[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
	}
	$present = array_change_key_case($headers, CASE_LOWER);
	if (!isset($present['user-agent']) && !empty($args['user-agent'])) {
		$headers['User-Agent'] = (string) $args['user-agent'];
	}
	if (!isset($present['cookie']) && !empty($args['cookies']) && is_array($args['cookies'])) {
		$pairs = [];
		foreach ($args['cookies'] as $name => $cookie) {
			if ($cookie instanceof WP_Http_Cookie) {
				$pairs[] = $cookie->getHeaderValue();
			} elseif (is_scalar($cookie)) {
				$pairs[] = $name . '=' . $cookie;
			}
		}
		if ($pairs !== []) {
			$headers['Cookie'] = implode('; ', $pairs);
		}
	}
	return $headers;
}

/**
 * Sends one request through the host and returns what came back.
 *
 * @param array<string, string> $headers
 *
 * @return array{status: int, headers: array<string, string>, body: string, truncated: bool}|string
 *   The reply, or the reason there is none.
 */
function drupflare_http_exchange(
	string $method,
	string $url,
	array $headers,
	string $body,
	int $timeoutMs,
	int $maxBytes,
): array|string {
	$state = &drupflare_http_state();
	if ($state['spent'] >= drupflare_http_budget()) {
		return sprintf(
			'this request already made %d outbound requests, which is the limit for this plan',
			$state['spent'],
		);
	}
	$state['spent']++;
	$descriptor = json_encode([
		'method' => $method,
		'url' => $url,
		'headers' => (object) $headers,
		'body' => $body === '' ? '' : base64_encode($body),
		'redirect' => 'manual',
		'timeoutMs' => $timeoutMs,
		'maxBytes' => $maxBytes,
	]);
	$raw =
		$descriptor === false
			? false
			: Park::yieldTo(DRUPFLARE_HTTP_SCHEME . base64_encode($descriptor));
	if (!is_string($raw)) {
		$frames = Park::refusal()['frames'];
		return 'the call could not be suspended for the host' .
			($frames === [] ? '' : ' under ' . implode(', ', $frames));
	}
	$reply = json_decode($raw, true);
	if (!is_array($reply)) {
		return 'the host answered something that is not JSON';
	}
	if (isset($reply['error']) && $reply['error'] !== '') {
		return (string) $reply['error'];
	}
	$decoded = base64_decode((string) ($reply['body'] ?? ''), true);
	$received = [];
	foreach ((array) ($reply['headers'] ?? []) as $name => $value) {
		$received[strtolower((string) $name)] = (string) $value;
	}
	$state['calls'][] = [
		'host' => (string) (parse_url($url, PHP_URL_HOST) ?? ''),
		'status' => (int) ($reply['status'] ?? 0),
	];
	return [
		'status' => (int) ($reply['status'] ?? 0),
		'headers' => $received,
		'setCookie' => array_values(array_map('strval', (array) ($reply['setCookie'] ?? []))),
		'body' => $decoded === false ? '' : $decoded,
		'truncated' => ($reply['truncated'] ?? false) === true,
	];
}

/** the body as the server meant it, for an encoding the runtime left on */
function drupflare_http_decode_body(string $body, array &$headers): string
{
	$encoding = strtolower(trim($headers['content-encoding'] ?? ''));
	if ($encoding === '' || $encoding === 'identity') {
		return $body;
	}
	$plain = false;
	if (in_array($encoding, ['gzip', 'x-gzip'], true) && function_exists('gzdecode')) {
		$plain = @gzdecode($body);
	} elseif ($encoding === 'deflate' && function_exists('gzinflate')) {
		$plain = @gzuncompress($body);
		if ($plain === false) {
			$plain = @gzinflate($body);
		}
	}
	if ($plain === false) {
		return $body;
	}
	unset($headers['content-encoding'], $headers['content-length']);
	return $plain;
}

function drupflare_http_next_hop(int $status, string $method, string $body): array
{
	$toGet =
		(($status === 301 || $status === 302) && $method === 'POST') ||
		($status === 303 && $method !== 'GET' && $method !== 'HEAD');
	return $toGet ? ['GET', ''] : [$method, $body];
}

/**
 * Builds the array wp_remote_request() returns from a host reply.
 *
 * @param array{status: int, headers: array<string, string>, body: string} $reply
 *
 * @return array<string, mixed>
 */
function drupflare_http_wp_response(
	array $reply,
	string $url,
	int $redirects,
	string $filename,
): array {
	$response = new WpOrg\Requests\Response();
	$response->body = $reply['body'];
	$response->raw = '';
	$response->status_code = $reply['status'];
	$response->protocol_version = 1.1;
	$response->success = $reply['status'] >= 200 && $reply['status'] < 300;
	$response->redirects = $redirects;
	$response->url = $url;
	$response->headers = new WpOrg\Requests\Response\Headers();
	foreach ($reply['headers'] as $name => $value) {
		if ($name !== 'set-cookie') {
			$response->headers[$name] = $value;
		}
	}
	foreach ($reply['setCookie'] as $cookie) {
		$response->headers['set-cookie'] = $cookie;
	}
	$response->cookies = new WpOrg\Requests\Cookie\Jar();
	foreach (
		WpOrg\Requests\Cookie::parse_from_headers($response->headers, new WpOrg\Requests\Iri($url))
		as $cookie
	) {
		$response->cookies[$cookie->name] = $cookie;
	}
	$http = new WP_HTTP_Requests_Response($response, $filename);
	$out = $http->to_array();
	$out['http_response'] = $http;
	return $out;
}

/**
 * Answers a WordPress HTTP request through the host, or refuses it by name.
 *
 * Runs on pre_http_request, before WordPress validates the URL, so the address policy is applied
 * here and again by the host on every hop.
 *
 * @param false|array|WP_Error $pre
 * @param array<string, mixed> $args
 *
 * @return false|array|WP_Error
 */
function drupflare_http_pre_request($pre, $args, $url)
{
	if ($pre !== false || !is_array($args)) {
		return $pre;
	}
	$url = drupflare_http_upgrade_url((string) $url);
	$fail = static fn(string $why): WP_Error => new WP_Error(
		'http_request_failed',
		'drupflare: ' . $why,
	);
	$refusal = drupflare_http_refusal($url);
	if ($refusal !== null) {
		drupflare_http_note_refusal();
		return $fail('the request was refused: ' . $refusal);
	}
	if (_wp_http_get_object()->block_request($url)) {
		/* translators: %s: URL to which the HTTP request was blocked. */
		return new WP_Error(
			'http_request_not_executed',
			sprintf(__('User has blocked requests through HTTP to the URL: %s.'), $url),
		);
	}
	if (!drupflare_http_available()) {
		return $fail('outbound requests are not available in this deployment');
	}
	if (!empty($args['reject_unsafe_urls'])) {
		$port = (int) (parse_url($url, PHP_URL_PORT) ?: 0);
		$ports = apply_filters(
			'http_allowed_safe_ports',
			[80, 443, 8080],
			(string) parse_url($url, PHP_URL_HOST),
			$url,
		);
		if ($port !== 0 && !in_array($port, (array) $ports, true)) {
			return $fail(
				'the request was refused: port ' . $port . ' is not allowed for a safe request',
			);
		}
	}
	if (($args['sslverify'] ?? true) === false && str_starts_with($url, 'https:')) {
		Degradation::record(
			'wordpress http sslverify',
			'a request asked for certificate checks to be off, and the host always checks them',
			'blocked',
		);
	}

	$method = strtoupper((string) ($args['method'] ?? 'GET'));
	$headers = drupflare_http_request_headers($args);
	$data = $args['body'] ?? '';
	if (is_array($data) || is_object($data)) {
		$data = http_build_query((array) $data, '', '&');
		if (!preg_grep('/^content-type$/i', array_keys($headers))) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
		}
	}
	$data = (string) ($data ?? '');
	$timeout = (float) ($args['timeout'] ?? 0);
	$timeoutMs =
		$timeout > 0
			? min(DRUPFLARE_HTTP_MAX_TIMEOUT_MS, (int) ceil($timeout * 1000))
			: DRUPFLARE_HTTP_DEFAULT_TIMEOUT_MS;
	$ceiling = drupflare_http_max_bytes();
	$limit = isset($args['limit_response_size']) ? (int) $args['limit_response_size'] : 0;
	$asked = $limit > 0 && $limit <= $ceiling;
	$maxBytes = $asked ? $limit : $ceiling;
	$hops = max(0, (int) ($args['redirection'] ?? 5));

	$redirects = 0;
	$current = $url;
	while (true) {
		$reply = drupflare_http_exchange($method, $current, $headers, $data, $timeoutMs, $maxBytes);
		if (is_string($reply)) {
			return $fail($reply);
		}
		$location = $reply['headers']['location'] ?? '';
		$status = $reply['status'];
		if (
			!in_array($status, [301, 302, 303, 307, 308], true) ||
			$location === '' ||
			$hops === 0
		) {
			break;
		}
		if ($redirects >= $hops) {
			return $fail('too many redirects');
		}
		$resolved = WpOrg\Requests\Iri::absolutize($current, $location);
		$next = $resolved === false ? '' : (string) $resolved;
		$refusal = drupflare_http_refusal($next);
		if ($refusal !== null) {
			drupflare_http_note_refusal();
			return $fail('a redirect was refused: ' . $refusal);
		}
		if (parse_url($next, PHP_URL_HOST) !== parse_url($current, PHP_URL_HOST)) {
			foreach (array_keys($headers) as $name) {
				if (
					in_array(
						strtolower($name),
						['authorization', 'cookie', 'proxy-authorization'],
						true,
					)
				) {
					unset($headers[$name]);
				}
			}
		}
		[$method, $data] = drupflare_http_next_hop($status, $method, $data);
		if ($data === '') {
			foreach (array_keys($headers) as $name) {
				if (in_array(strtolower($name), ['content-type', 'content-length'], true)) {
					unset($headers[$name]);
				}
			}
		}
		$current = $next;
		$redirects++;
	}

	if ($reply['truncated'] && !$asked) {
		return $fail(
			sprintf(
				'the response is larger than %d bytes, which is the most this host reads',
				$ceiling,
			),
		);
	}
	$reply['body'] = drupflare_http_decode_body($reply['body'], $reply['headers']);
	$filename = '';
	if (!empty($args['stream']) && !empty($args['filename'])) {
		$filename = (string) $args['filename'];
		if (@file_put_contents($filename, $reply['body']) === false) {
			return $fail('the response could not be written to ' . $filename);
		}
		$reply['body'] = '';
	}
	if (isset($args['blocking']) && $args['blocking'] === false) {
		return [
			'headers' => [],
			'body' => '',
			'response' => ['code' => false, 'message' => false],
			'cookies' => [],
			'http_response' => null,
		];
	}
	return drupflare_http_wp_response($reply, $current, $redirects, $filename);
}
