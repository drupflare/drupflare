<?php

declare(strict_types=1);

namespace Drupal\drupflare\Exec;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\drupflare\Degradation;
use Drupal\drupflare\Http\Park;
use Drupal\drupflare\Shim\Finfo;
use Drupal\drupflare\Shim\ZipArchive;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The programs {@see Router} serves without the network.
 *
 * Each takes the argv after the program name and a context (`cwd`, `stdin`, `env`, `fetch`) and
 * answers `[stdout, stderr, exit code]`. An option a program does not take here throws
 * {@see Refused} by name rather than being ignored.
 */
final class Commands
{
	/**
	 * Runs one program of Router::PROGRAMS.
	 *
	 * @param string $program
	 *   The program name.
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function dispatch(string $program, array $args, array $ctx): array
	{
		return match ($program) {
			'true' => self::trueCommand($args, $ctx),
			'false' => self::falseCommand($args, $ctx),
			'echo' => self::echoCommand($args, $ctx),
			'date' => self::date($args, $ctx),
			'hostname' => self::hostname($args, $ctx),
			'whoami' => self::whoami($args, $ctx),
			'uname' => self::uname($args, $ctx),
			'sha256sum' => self::sha256sum($args, $ctx),
			'md5sum' => self::md5sum($args, $ctx),
			'base64' => self::base64($args, $ctx),
			'sleep' => self::sleep($args, $ctx),
			'which' => self::which($args, $ctx),
			'file' => self::file($args, $ctx),
			'unzip' => self::unzip($args, $ctx),
			'zip' => self::zip($args, $ctx),
			'gzip' => self::gzip($args, $ctx),
			'gunzip' => self::gunzip($args, $ctx),
			'wget' => Net::wget($args, $ctx),
			'curl' => Net::curl($args, $ctx),
			default => throw new Refused('not served'),
		};
	}

	/**
	 * Resolves a path against the working directory.
	 *
	 * @param array{cwd: string} $ctx
	 *   The context; only the working directory is read.
	 * @param string $path
	 *   An absolute path, a stream URI or a relative path.
	 *
	 * @return string
	 *   The path to open.
	 */
	private static function path(array $ctx, string $path): string
	{
		return str_starts_with($path, '/') || str_contains($path, '://')
			? $path
			: rtrim($ctx['cwd'], '/') . '/' . $path;
	}

	/**
	 * Splits combined short options (`-rq`) when every letter is a known option letter.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param string $letters
	 *   The option letters that may be combined.
	 *
	 * @return string[]
	 *   The words with combined options split.
	 */
	public static function expand(array $args, string $letters): array
	{
		$out = [];
		foreach ($args as $arg) {
			if (
				preg_match('/^-[A-Za-z0-9]{2,}$/', $arg) === 1 &&
				strspn(substr($arg, 1), $letters) === strlen($arg) - 1
			) {
				foreach (str_split(substr($arg, 1)) as $letter) {
					$out[] = '-' . $letter;
				}
			} else {
				$out[] = $arg;
			}
		}
		return $out;
	}

	/**
	 * Splits options from operands; a bare `--` ends the options.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 *
	 * @return array{0: string[], 1: string[]}
	 *   Options, then operands.
	 */
	private static function split(array $args): array
	{
		$flags = [];
		$operands = [];
		$done = false;
		foreach ($args as $arg) {
			if (!$done && $arg === '--') {
				$done = true;
			} elseif (!$done && strlen($arg) > 1 && $arg[0] === '-') {
				$flags[] = $arg;
			} else {
				$operands[] = $arg;
			}
		}
		return [$flags, $operands];
	}

	/**
	 * Exits 0 with no output.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 */
	public static function trueCommand(array $args, array $ctx): array
	{
		return ['', '', 0];
	}

	/**
	 * Exits 1 with no output.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 */
	public static function falseCommand(array $args, array $ctx): array
	{
		return ['', '', 1];
	}

	/**
	 * Prints its words, with -n and -e support.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 */
	public static function echoCommand(array $args, array $ctx): array
	{
		$newline = true;
		$escapes = false;
		while ($args !== [] && preg_match('/^-[neE]+$/', $args[0]) === 1) {
			$flag = array_shift($args);
			$newline = $newline && !str_contains($flag, 'n');
			if (str_contains($flag, 'e')) {
				$escapes = true;
			} elseif (str_contains($flag, 'E')) {
				$escapes = false;
			}
		}
		$text = implode(' ', $args);
		if ($escapes) {
			$text = strtr($text, ['\\n' => "\n", '\\t' => "\t", '\\\\' => '\\']);
		}
		return [$text . ($newline ? "\n" : ''), '', 0];
	}

	/**
	 * Prints a formatted time, in UTC with -u.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function date(array $args, array $ctx): array
	{
		$time = time();
		$utc = false;
		$format = '%a %b %e %H:%M:%S %Z %Y';
		while ($args !== []) {
			$arg = array_shift($args);
			if ($arg === '-u' || $arg === '--utc' || $arg === '--universal') {
				$utc = true;
			} elseif ($arg === '-d' || str_starts_with($arg, '--date=')) {
				$value = $arg === '-d' ? (string) array_shift($args) : substr($arg, 7);
				$parsed = str_starts_with($value, '@')
					? (int) substr($value, 1)
					: strtotime($value);
				if ($parsed === false) {
					return ['', "date: invalid date '$value'\n", 1];
				}
				$time = $parsed;
			} elseif ($arg === '-I' || str_starts_with($arg, '--iso-8601')) {
				$format = '%Y-%m-%d';
			} elseif ($arg === '-R' || $arg === '--rfc-email') {
				$format = '%a, %d %b %Y %H:%M:%S %z';
			} elseif (str_starts_with($arg, '+')) {
				$format = substr($arg, 1);
			} else {
				throw new Refused('unsupported option ' . $arg);
			}
		}
		$zone = $utc ? 'UTC' : date_default_timezone_get();
		$when = (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone($zone));
		$map = [
			'%a' => 'D',
			'%A' => 'l',
			'%b' => 'M',
			'%B' => 'F',
			'%d' => 'd',
			'%H' => 'H',
			'%I' => 'h',
			'%m' => 'm',
			'%M' => 'i',
			'%p' => 'A',
			'%S' => 's',
			'%u' => 'N',
			'%w' => 'w',
			'%y' => 'y',
			'%Y' => 'Y',
			'%z' => 'O',
			'%Z' => 'T',
			'%s' => 'U',
			'%F' => 'Y-m-d',
			'%T' => 'H:i:s',
			'%D' => 'm/d/y',
		];
		$out = preg_replace_callback(
			'/%[a-zA-Z%]/',
			static fn(array $m): string => match ($m[0]) {
				'%%' => '%',
				'%n' => "\n",
				'%t' => "\t",
				'%N' => '000000000',
				'%e' => str_pad($when->format('j'), 2, ' ', STR_PAD_LEFT),
				'%j' => str_pad((string) ((int) $when->format('z') + 1), 3, '0', STR_PAD_LEFT),
				default => isset($map[$m[0]]) ? $when->format($map[$m[0]]) : $m[0],
			},
			$format,
		);
		return [(string) $out . "\n", '', 0];
	}

	/**
	 * Prints the runtime hostname.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 */
	public static function hostname(array $args, array $ctx): array
	{
		return [(string) gethostname() . "\n", '', 0];
	}

	/**
	 * Prints the fixed account name.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 */
	public static function whoami(array $args, array $ctx): array
	{
		return ["drupflare\n", '', 0];
	}

	/**
	 * Prints system information from php_uname().
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function uname(array $args, array $ctx): array
	{
		$mode = 's';
		foreach ($args as $arg) {
			if (preg_match('/^-[asnrvm]+$/', $arg) !== 1) {
				throw new Refused('unsupported option ' . $arg);
			}
			$mode = substr($arg, 1);
		}
		if (str_contains($mode, 'a')) {
			return [php_uname('a') . "\n", '', 0];
		}
		$parts = [];
		foreach (str_split($mode) as $m) {
			$parts[] = php_uname($m);
		}
		return [implode(' ', $parts) . "\n", '', 0];
	}

	/**
	 * Prints a digest line per file or for standard input.
	 *
	 * @param string $algo
	 *   The hash() algorithm.
	 * @param string $name
	 *   The program name, for messages.
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	private static function digest(string $algo, string $name, array $args, array $ctx): array
	{
		[$flags, $files] = self::split($args);
		foreach ($flags as $flag) {
			if (!in_array($flag, ['-b', '-t', '--binary', '--text'], true)) {
				throw new Refused('unsupported option ' . $flag);
			}
		}
		$out = '';
		$err = '';
		$code = 0;
		foreach ($files === [] ? ['-'] : $files as $file) {
			$bytes = $file === '-' ? $ctx['stdin'] : @file_get_contents(self::path($ctx, $file));
			if ($bytes === false) {
				$err .= "$name: $file: No such file or directory\n";
				$code = 1;
				continue;
			}
			$out .= hash($algo, $bytes) . '  ' . $file . "\n";
		}
		return [$out, $err, $code];
	}

	/**
	 * Prints SHA-256 digests.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function sha256sum(array $args, array $ctx): array
	{
		return self::digest('sha256', 'sha256sum', $args, $ctx);
	}

	/**
	 * Prints MD5 digests.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function md5sum(array $args, array $ctx): array
	{
		return self::digest('md5', 'md5sum', $args, $ctx);
	}

	/**
	 * Encodes or decodes base64, wrapping at 76 columns like GNU.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function base64(array $args, array $ctx): array
	{
		$decode = false;
		$wrap = 76;
		$file = '-';
		while ($args !== []) {
			$arg = array_shift($args);
			if ($arg === '-d' || $arg === '-D' || $arg === '--decode') {
				$decode = true;
			} elseif ($arg === '-w' || $arg === '--wrap') {
				$wrap = (int) array_shift($args);
			} elseif (str_starts_with($arg, '--wrap=')) {
				$wrap = (int) substr($arg, 7);
			} elseif ($arg === '-' || $arg[0] !== '-') {
				$file = $arg;
			} else {
				throw new Refused('unsupported option ' . $arg);
			}
		}
		$bytes = $file === '-' ? $ctx['stdin'] : @file_get_contents(self::path($ctx, $file));
		if ($bytes === false) {
			return ['', "base64: $file: No such file or directory\n", 1];
		}
		if ($decode) {
			$raw = base64_decode((string) preg_replace('/\s+/', '', $bytes), true);
			return $raw === false ? ['', "base64: invalid input\n", 1] : [$raw, '', 0];
		}
		$encoded = base64_encode($bytes);
		if ($wrap <= 0) {
			return [$encoded, '', 0];
		}
		return [$encoded === '' ? '' : chunk_split($encoded, $wrap, "\n"), '', 0];
	}

	/**
	 * Waits through the park, cut short past the invocation allowance.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 */
	public static function sleep(array $args, array $ctx): array
	{
		if ($args === []) {
			return ['', "sleep: missing operand\n", 1];
		}
		$units = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];
		$seconds = 0.0;
		foreach ($args as $arg) {
			if (preg_match('/^(\d+(?:\.\d+)?)([smhd]?)$/', $arg, $m) !== 1) {
				return ['', "sleep: invalid time interval '$arg'\n", 1];
			}
			$seconds += (float) $m[1] * $units[$m[2]];
		}
		$ms = (int) ceil($seconds * 1000);
		$waited = Park::sleep($ms);
		if ($waited['slept'] < $ms) {
			Degradation::record(
				'sleep',
				sprintf(
					'asked to wait %d ms and waited %d ms; the wait runs through the host and the invocation had %d ms of its allowance left',
					$ms,
					$waited['slept'],
					$waited['remaining'],
				),
				'untested',
			);
		}
		return ['', '', 0];
	}

	/**
	 * Prints the path of each served program.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 */
	public static function which(array $args, array $ctx): array
	{
		$out = '';
		$code = 0;
		foreach ($args as $name) {
			if ($name === '-a') {
				continue;
			}
			if (in_array($name, Router::PROGRAMS, true)) {
				$out .= '/usr/bin/' . $name . "\n";
			} else {
				$code = 1;
			}
		}
		return [$out, '', $code];
	}

	/**
	 * Prints the MIME type of each file.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function file(array $args, array $ctx): array
	{
		[$flags, $files] = self::split(self::expand($args, 'bi'));
		$brief = false;
		$mode = null;
		foreach ($flags as $flag) {
			if ($flag === '-b' || $flag === '--brief') {
				$brief = true;
			} elseif ($flag === '--mime-type') {
				$mode = Finfo::MIME_TYPE;
			} elseif ($flag === '--mime' || $flag === '-i') {
				$mode = Finfo::MIME;
			} else {
				throw new Refused('unsupported option ' . $flag);
			}
		}
		if ($mode === null) {
			throw new Refused('there is no description database; use --mime-type or --mime');
		}
		$finfo = new Finfo($mode);
		$out = '';
		$err = '';
		$code = 0;
		foreach ($files as $file) {
			$real = self::path($ctx, $file);
			$type = is_file($real) ? $finfo->file($real) : false;
			if ($type === false) {
				$err .= "file: cannot open '$file' (No such file or directory)\n";
				$code = 1;
				continue;
			}
			$out .= ($brief ? '' : $file . ': ') . $type . "\n";
		}
		return [$out, $err, $code];
	}

	/**
	 * Extracts a zip archive with ZipArchive.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function unzip(array $args, array $ctx): array
	{
		$args = self::expand($args, 'onqp');
		$overwrite = false;
		$never = false;
		$quiet = false;
		$pipe = false;
		$dir = $ctx['cwd'];
		$operands = [];
		while ($args !== []) {
			$arg = array_shift($args);
			if ($arg === '-o') {
				$overwrite = true;
			} elseif ($arg === '-n') {
				$never = true;
			} elseif ($arg === '-q') {
				$quiet = true;
			} elseif ($arg === '-p') {
				$pipe = true;
			} elseif ($arg === '-d') {
				$dir = self::path($ctx, (string) array_shift($args));
			} elseif ($arg[0] === '-' && strlen($arg) > 1) {
				throw new Refused('unsupported option ' . $arg);
			} else {
				$operands[] = $arg;
			}
		}
		$archive = array_shift($operands);
		if ($archive === null) {
			return ['', "unzip: missing archive\n", 1];
		}
		$zip = new ZipArchive();
		if ($zip->open(self::path($ctx, $archive)) !== true) {
			return ['', "unzip: cannot find or open $archive\n", 9];
		}
		$out = $quiet || $pipe ? '' : "Archive:  $archive\n";
		$members = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string) $zip->getNameIndex($i);
			if ($operands === [] || in_array($name, $operands, true)) {
				$members[] = $name;
			}
		}
		if ($members === [] && $operands !== []) {
			return ['', "unzip: filename not matched: {$operands[0]}\n", 11];
		}
		$bytes = '';
		foreach ($members as $name) {
			$isDir = str_ends_with($name, '/');
			if ($pipe) {
				$bytes .= $isDir ? '' : (string) $zip->getFromName($name);
				continue;
			}
			$target = rtrim($dir, '/') . '/' . $name;
			if (!$isDir && file_exists($target) && !$overwrite) {
				if ($never) {
					continue;
				}
				$zip->close();
				return [$out, "unzip: $name exists; use -o to overwrite\n", 1];
			}
			if (!$zip->extractTo($dir, [$name])) {
				$zip->close();
				return [$out, "unzip: cannot extract $name\n", 1];
			}
			$out .= $quiet ? '' : ($isDir ? '   creating: ' : '  inflating: ') . $name . "\n";
		}
		$zip->close();
		return [$pipe ? $bytes : $out, '', 0];
	}

	/**
	 * Adds files to a zip archive with ZipArchive.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function zip(array $args, array $ctx): array
	{
		$quiet = false;
		$recurse = false;
		$junk = false;
		$store = false;
		$operands = [];
		foreach (self::expand($args, 'qrj0123456789') as $arg) {
			if ($arg === '-q') {
				$quiet = true;
			} elseif ($arg === '-r') {
				$recurse = true;
			} elseif ($arg === '-j') {
				$junk = true;
			} elseif (preg_match('/^-[0-9]$/', $arg) === 1) {
				$store = $arg === '-0';
			} elseif ($arg[0] === '-' && strlen($arg) > 1) {
				throw new Refused('unsupported option ' . $arg);
			} else {
				$operands[] = $arg;
			}
		}
		$archive = array_shift($operands);
		if ($archive === null || $operands === []) {
			return ['', "zip error: nothing to do\n", 12];
		}
		$zip = new ZipArchive();
		if ($zip->open(self::path($ctx, $archive), ZipArchive::CREATE) !== true) {
			return ['', "zip error: could not create $archive\n", 15];
		}
		$out = '';
		$add = static function (string $real, string $name) use (
			$zip,
			$store,
			$quiet,
			&$out,
		): void {
			if (is_dir($real)) {
				$zip->addEmptyDir(rtrim($name, '/'));
				$out .= $quiet ? '' : '  adding: ' . rtrim($name, '/') . "/\n";
				return;
			}
			$zip->addFromString($name, (string) file_get_contents($real));
			if ($store) {
				$zip->setCompressionName($name, ZipArchive::CM_STORE);
			}
			$out .= $quiet ? '' : '  adding: ' . $name . "\n";
		};
		$err = '';
		$code = 0;
		foreach ($operands as $operand) {
			$real = self::path($ctx, $operand);
			if (!file_exists($real)) {
				$err .= "zip warning: name not matched: $operand\n";
				$code = 12;
				continue;
			}
			$base = ltrim($operand, './');
			$add($real, $junk ? basename($operand) : $base);
			if ($recurse && is_dir($real)) {
				$walk = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
					RecursiveIteratorIterator::SELF_FIRST,
				);
				foreach ($walk as $item) {
					$rel = substr((string) $item->getPathname(), strlen(rtrim($real, '/')) + 1);
					$add(
						(string) $item->getPathname(),
						$junk ? basename($rel) : rtrim($base, '/') . '/' . $rel,
					);
				}
			}
		}
		$zip->close();
		return [$out, $err, $code];
	}

	/**
	 * Compresses or decompresses with zlib, in place or through standard input.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 * @param bool $decompress
	 *   Whether the program is gunzip.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	private static function deflate(array $args, array $ctx, bool $decompress): array
	{
		$name = $decompress ? 'gunzip' : 'gzip';
		$stdout = false;
		$keep = false;
		$force = false;
		$level = 6;
		$files = [];
		foreach (self::expand($args, 'cdkfqn123456789') as $arg) {
			if ($arg === '-c' || $arg === '--stdout' || $arg === '--to-stdout') {
				$stdout = true;
			} elseif ($arg === '-d' || $arg === '--decompress' || $arg === '--uncompress') {
				$decompress = true;
			} elseif ($arg === '-k' || $arg === '--keep') {
				$keep = true;
			} elseif ($arg === '-f' || $arg === '--force') {
				$force = true;
			} elseif (in_array($arg, ['-q', '--quiet', '-n', '--no-name'], true)) {
				continue;
			} elseif (preg_match('/^-[1-9]$/', $arg) === 1) {
				$level = (int) $arg[1];
			} elseif ($arg === '--fast') {
				$level = 1;
			} elseif ($arg === '--best') {
				$level = 9;
			} elseif ($arg === '-' || $arg[0] !== '-') {
				$files[] = $arg;
			} else {
				throw new Refused('unsupported option ' . $arg);
			}
		}
		$transform = static fn(string $bytes): string|false => $decompress
			? @gzdecode($bytes)
			: gzencode($bytes, $level);
		if ($files === [] || $files === ['-']) {
			$result = $transform($ctx['stdin']);
			return $result === false
				? ['', "$name: stdin: not in gzip format\n", 1]
				: [$result, '', 0];
		}
		$out = '';
		$err = '';
		$code = 0;
		foreach ($files as $file) {
			$real = self::path($ctx, $file);
			$bytes = is_file($real) ? file_get_contents($real) : false;
			if ($bytes === false) {
				$err .= "$name: $file: No such file or directory\n";
				$code = 1;
				continue;
			}
			$result = $transform($bytes);
			if ($result === false) {
				$err .= "$name: $file: not in gzip format\n";
				$code = 1;
				continue;
			}
			if ($stdout) {
				$out .= $result;
				continue;
			}
			if ($decompress) {
				if (preg_match('/\.(gz|z)$/i', $real) === 1) {
					$target = (string) preg_replace('/\.(gz|z)$/i', '', $real);
				} elseif (str_ends_with($real, '.tgz')) {
					$target = substr($real, 0, -4) . '.tar';
				} else {
					$err .= "$name: $file: unknown suffix -- ignored\n";
					$code = 2;
					continue;
				}
			} else {
				$target = $real . '.gz';
			}
			if (file_exists($target) && !$force) {
				$err .= "$name: $target already exists; not overwritten\n";
				$code = 2;
				continue;
			}
			file_put_contents($target, $result);
			if (!$keep) {
				unlink($real);
			}
		}
		return [$out, $err, $code];
	}

	/**
	 * Compresses files or standard input.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function gzip(array $args, array $ctx): array
	{
		return self::deflate($args, $ctx, false);
	}

	/**
	 * Decompresses files or standard input.
	 *
	 * @param string[] $args
	 *   The words after the program name.
	 * @param array{cwd: string, stdin: string, env: array<string, string>, fetch: callable|null} $ctx
	 *   Working directory, standard input, environment and fetch transport.
	 *
	 * @return array{0: string, 1: string, 2: int}
	 *   Standard output, standard error and the exit code.
	 *
	 * @throws Refused
	 *   For an option the program does not take here.
	 */
	public static function gunzip(array $args, array $ctx): array
	{
		return self::deflate($args, $ctx, true);
	}
}
