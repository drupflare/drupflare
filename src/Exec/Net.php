<?php

declare(strict_types=1);

namespace Drupal\drupflare\Exec;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Throwable;

/**
 * Serves wget and curl for one URL, over the fetch transport.
 *
 * Anything that would need more than one request or a feature the transport lacks is refused by
 * its option name: recursion and mirroring for wget, and multiple URLs, globbing, uploads, proxies
 * and disabled certificate checks for curl.
 */
final class Net
{
	private const WGET_REFUSED = [
		'-r' => 'recursive retrieval',
		'--recursive' => 'recursive retrieval',
		'-m' => 'mirroring',
		'--mirror' => 'mirroring',
		'-p' => 'page requisites',
		'--page-requisites' => 'page requisites',
		'-k' => 'link conversion',
		'--convert-links' => 'link conversion',
		'-np' => 'recursive retrieval',
		'--no-parent' => 'recursive retrieval',
		'-l' => 'recursive retrieval',
		'--level' => 'recursive retrieval',
		'-A' => 'recursive retrieval',
		'--accept' => 'recursive retrieval',
		'-R' => 'recursive retrieval',
		'--reject' => 'recursive retrieval',
		'-H' => 'recursive retrieval',
		'--span-hosts' => 'recursive retrieval',
		'-i' => 'input files with many URLs',
		'--input-file' => 'input files with many URLs',
		'--no-check-certificate' => 'disabled certificate checks',
		'-c' => 'resumed downloads',
		'--continue' => 'resumed downloads',
		'-S' => 'server response printing',
		'--server-response' => 'server response printing',
	];

	private const CURL_REFUSED = [
		'-k' => 'disabled certificate checks',
		'--insecure' => 'disabled certificate checks',
		'-x' => 'proxies',
		'--proxy' => 'proxies',
		'-F' => 'multipart uploads',
		'--form' => 'multipart uploads',
		'-T' => 'uploads',
		'--upload-file' => 'uploads',
		'-r' => 'range requests',
		'--range' => 'range requests',
		'-C' => 'resumed downloads',
		'--continue-at' => 'resumed downloads',
		'-Z' => 'parallel transfers',
		'--parallel' => 'parallel transfers',
		'-w' => 'write-out formats',
		'--write-out' => 'write-out formats',
		'-K' => 'config files',
		'--config' => 'config files',
		'-O' => 'remote-name output',
		'--remote-name' => 'remote-name output',
	];

	/**
	 * Fetches one URL like wget.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, fetch: callable|null} $ctx
	 *   Working directory, standard input and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option or a URL shape the transport cannot serve.
	 */
	public static function wget(array $args, array $ctx): array
	{
		$quiet = false;
		$output = null;
		$headers = [];
		$method = null;
		$body = null;
		$urls = [];
		$spider = false;
		while ($args !== []) {
			$arg = array_shift($args);
			$value = null;
			if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
				[$arg, $value] = explode('=', $arg, 2);
			}
			$name = self::WGET_REFUSED[$arg] ?? null;
			if ($name !== null) {
				throw new Refused("$arg is not supported ($name)");
			}
			$take = static function () use (&$args, &$value): string {
				return $value ?? (string) array_shift($args);
			};
			switch ($arg) {
				case '-q':
				case '--quiet':
				case '-nv':
				case '--no-verbose':
					$quiet = true;
					break;

				case '-nc':
				case '--no-clobber':
				case '--no-cache':
				case '--content-disposition':
					break;

				case '-O':
				case '--output-document':
					$output = $take();
					break;

				case '--header':
					$headers[] = $take();
					break;

				case '-U':
				case '--user-agent':
					$headers[] = 'User-Agent: ' . $take();
					break;

				case '--post-data':
					$body = $take();
					$method ??= 'POST';
					break;

				case '--post-file':
					$file = self::path($ctx, $take());
					$body = @file_get_contents($file);
					if ($body === false) {
						return ['', "wget: cannot read $file\n", 1];
					}
					$method ??= 'POST';
					break;

				case '--method':
					$method = strtoupper($take());
					break;

				case '--spider':
					$spider = true;
					$method = 'HEAD';
					break;

				case '-T':
				case '--timeout':
				case '-t':
				case '--tries':
					$take();
					break;

				default:
					if ($arg !== '-' && $arg[0] === '-' && strlen($arg) > 1) {
						throw new Refused('unsupported option ' . $arg);
					}
					$urls[] = $arg;
			}
		}
		if (count($urls) !== 1) {
			return $urls === []
				? ['', "wget: missing URL\n", 1]
				: throw new Refused('more than one URL needs a recursive or batch run');
		}
		$url = self::url($urls[0], 'https');
		try {
			$response = self::send($ctx, $method ?? 'GET', $url, $headers, $body, true, 0);
		} catch (Throwable $e) {
			return ['', 'wget: unable to resolve host address: ' . $e->getMessage() . "\n", 4];
		}
		if ($response->getStatusCode() >= 400) {
			return [
				'',
				sprintf(
					"ERROR %d: %s.\n",
					$response->getStatusCode(),
					$response->getReasonPhrase() ?: 'Error',
				),
				8,
			];
		}
		if ($spider) {
			return ['', '', 0];
		}
		$bytes = (string) $response->getBody();
		if ($output === '-') {
			return [$bytes, '', 0];
		}
		$target = self::path($ctx, $output ?? self::remoteName($url));
		if ($output === null) {
			$base = $target;
			for ($n = 1; file_exists($target); $n++) {
				$target = $base . '.' . $n;
			}
		}
		file_put_contents($target, $bytes);
		return ['', $quiet ? '' : "'$target' saved\n", 0];
	}

	/**
	 * Fetches one URL like curl.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, fetch: callable|null} $ctx
	 *   Working directory, standard input and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option or a URL shape the transport cannot serve.
	 */
	public static function curl(array $args, array $ctx): array
	{
		$follow = false;
		$fail = false;
		$include = false;
		$head = false;
		$output = null;
		$method = null;
		$headers = [];
		$body = null;
		$timeout = 0;
		$urls = [];
		$args = Commands::expand($args, 'sSLfiIkoXHAumdOFTrCZwxK');
		while ($args !== []) {
			$arg = array_shift($args);
			$value = null;
			if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
				[$arg, $value] = explode('=', $arg, 2);
			}
			$name = self::CURL_REFUSED[$arg] ?? null;
			if ($name !== null) {
				throw new Refused("$arg is not supported ($name)");
			}
			$take = static function () use (&$args, &$value): string {
				return $value ?? (string) array_shift($args);
			};
			switch ($arg) {
				case '-s':
				case '--silent':
				case '-S':
				case '--show-error':
				case '--compressed':
					break;

				case '--connect-timeout':
					$take();
					break;

				case '-L':
				case '--location':
					$follow = true;
					break;

				case '-f':
				case '--fail':
					$fail = true;
					break;

				case '-i':
				case '--include':
					$include = true;
					break;

				case '-I':
				case '--head':
					$head = true;
					$method = 'HEAD';
					break;

				case '-o':
				case '--output':
					$output = $take();
					break;

				case '-X':
				case '--request':
					$method = strtoupper($take());
					break;

				case '-H':
				case '--header':
					$headers[] = $take();
					break;

				case '-A':
				case '--user-agent':
					$headers[] = 'User-Agent: ' . $take();
					break;

				case '-u':
				case '--user':
					$headers[] = 'Authorization: Basic ' . base64_encode($take());
					break;

				case '-m':
				case '--max-time':
					$timeout = (int) ceil((float) $take());
					break;

				case '-d':
				case '--data':
				case '--data-raw':
				case '--data-binary':
					$data = $take();
					if ($arg !== '--data-raw' && str_starts_with($data, '@')) {
						$data =
							$data === '@-'
								? $ctx['stdin']
								: (string) @file_get_contents(self::path($ctx, substr($data, 1)));
					}
					$body = $body === null ? $data : $body . '&' . $data;
					$method ??= 'POST';
					break;

				case '--url':
					$urls[] = $take();
					break;

				default:
					if ($arg[0] === '-' && strlen($arg) > 1) {
						throw new Refused('unsupported option ' . $arg);
					}
					$urls[] = $arg;
			}
		}
		if (count($urls) !== 1) {
			return $urls === []
				? ['', "curl: no URL specified\n", 2]
				: throw new Refused('more than one URL needs parallel or batch transfers');
		}
		if (preg_match('/[{}\[\]]/', $urls[0]) === 1) {
			throw new Refused('URL globbing is not supported');
		}
		if ($body !== null && !self::hasHeader($headers, 'Content-Type')) {
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		}
		$url = self::url($urls[0], 'http');
		try {
			$response = self::send(
				$ctx,
				$method ?? 'GET',
				$url,
				$headers,
				$body,
				$follow,
				$timeout,
			);
		} catch (Throwable $e) {
			return ['', 'curl: (7) Failed to connect: ' . $e->getMessage() . "\n", 7];
		}
		$status = $response->getStatusCode();
		if ($fail && $status >= 400) {
			return ['', "curl: (22) The requested URL returned error: $status\n", 22];
		}
		$out = '';
		if ($include || $head) {
			$out .= sprintf("HTTP/1.1 %d %s\r\n", $status, $response->getReasonPhrase());
			foreach ($response->getHeaders() as $header => $values) {
				$out .= $header . ': ' . implode(', ', $values) . "\r\n";
			}
			$out .= "\r\n";
		}
		$bytes = $head ? '' : (string) $response->getBody();
		if ($output !== null && $output !== '-') {
			file_put_contents(self::path($ctx, $output), $bytes);
			return [$out, '', 0];
		}
		return [$out . $bytes, '', 0];
	}

	/**
	 * Whether a header list already names a header.
	 *
	 * @param string[] $headers
	 *   Header lines as `Name: value`.
	 * @param string $name
	 *   The header name to look for.
	 *
	 * @return bool
	 *   True when the header is present.
	 */
	private static function hasHeader(array $headers, string $name): bool
	{
		foreach ($headers as $header) {
			if (strncasecmp($header, $name . ':', strlen($name) + 1) === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves a path against the working directory.
	 *
	 * @param array{cwd: string} $ctx
	 *   The context; only the working directory is read.
	 * @param string $path
	 *   An absolute or relative path.
	 *
	 * @return string
	 *   The path to open.
	 */
	private static function path(array $ctx, string $path): string
	{
		return str_starts_with($path, '/') ? $path : rtrim($ctx['cwd'], '/') . '/' . $path;
	}

	/**
	 * Adds the scheme a bare host implies.
	 *
	 * @param string $url
	 *   The URL as typed.
	 * @param string $scheme
	 *   The scheme to assume.
	 *
	 * @return string
	 *   A URL with an http or https scheme.
	 *
	 * @throws Refused
	 *   For any other scheme.
	 */
	private static function url(string $url, string $scheme): string
	{
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
			return $scheme . '://' . $url;
		}
		if (preg_match('#^https?://#i', $url) !== 1) {
			throw new Refused('only http and https URLs are served');
		}
		return $url;
	}

	/**
	 * The file name wget saves a URL under.
	 *
	 * @param string $url
	 *   The URL.
	 *
	 * @return string
	 *   The last path segment, or index.html.
	 */
	private static function remoteName(string $url): string
	{
		$name = basename((string) parse_url($url, PHP_URL_PATH));
		return $name === '' || $name === '/' ? 'index.html' : $name;
	}

	/**
	 * Sends one request over the fetch transport.
	 *
	 * @param array{cwd: string, stdin: string, fetch: callable|null} $ctx
	 *   The context; the transport is read.
	 * @param string $method
	 *   The HTTP method.
	 * @param string $url
	 *   The absolute URL.
	 * @param string[] $headers
	 *   Header lines as `Name: value`.
	 * @param string|null $body
	 *   The request body, if any.
	 * @param bool $follow
	 *   Whether to follow redirects.
	 * @param int $timeout
	 *   Seconds before giving up; 0 for the transport default.
	 *
	 * @return Response
	 *   The response.
	 *
	 * @throws Refused
	 *   When the transport answers something that is not a response.
	 */
	private static function send(
		array $ctx,
		string $method,
		string $url,
		array $headers,
		?string $body,
		bool $follow,
		int $timeout,
	): Response {
		$map = [];
		foreach ($headers as $header) {
			[$name, $value] = array_pad(explode(':', $header, 2), 2, '');
			$map[trim($name)] = trim($value);
		}
		$options = ['allow_redirects' => $follow];
		if ($timeout > 0) {
			$options['timeout'] = $timeout;
		}
		$fetch = $ctx['fetch'];
		$response = $fetch(new Request($method, $url, $map, $body), $options)->wait();
		if (!($response instanceof Response)) {
			throw new Refused('the transport answered something that is not a response');
		}
		return $response;
	}
}
