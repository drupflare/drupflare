<?php

declare(strict_types=1);

namespace Drupal\drupflare\Exec;

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Http\ParkFetchHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Runs a command line without a shell or a process table.
 *
 * A line is tokenised into argv and the program name is looked up in a fixed table of commands the
 * runtime can honour itself. There is no `sh -c`: a pipe, a redirect, `&`, `;`, a substitution or a
 * glob is refused with a named reason, because serving half of a shell would answer some lines
 * wrongly and the rest not at all.
 *
 * Outcomes, counted per program in {@see self::counters()}:
 * - `ok` and `fail`: a served program that exited 0 or not;
 * - `refused`: a shell construct or an option the program does not take here;
 * - `unknown`: a program outside the table;
 * - `unavailable`: a served program whose transport is missing in this deployment.
 *
 * The last three are a failed launch (exit 127) and record a {@see Degradation}.
 */
final class Router
{
	/**
	 * The programs the runtime serves.
	 *
	 * @var string[]
	 */
	public const PROGRAMS = [
		'true',
		'false',
		'echo',
		'date',
		'hostname',
		'whoami',
		'uname',
		'sha256sum',
		'md5sum',
		'base64',
		'sleep',
		'which',
		'file',
		'unzip',
		'zip',
		'gzip',
		'gunzip',
		'wget',
		'curl',
	];

	/**
	 * Programs that reach the network through the fetch transport.
	 */
	private const NETWORK = ['wget', 'curl'];

	/**
	 * Per-program outcome counts for this boot, keyed `program:outcome`.
	 *
	 * @var array<string, int>
	 */
	private static array $counters = [];

	/**
	 * The instance the process functions use.
	 *
	 * @var self|null
	 */
	private static ?self $shared = null;

	/**
	 * Builds a router.
	 *
	 * @param (callable(RequestInterface, array): PromiseInterface)|null $fetch
	 *   The transport wget and curl use; null picks the park transport when the runtime has one.
	 */
	public function __construct(private $fetch = null) {}

	/**
	 * The instance the process functions use.
	 *
	 * @return self
	 *   The shared router.
	 */
	public static function shared(): self
	{
		return self::$shared ??= new self();
	}

	/**
	 * Replaces the shared instance.
	 *
	 * @param self|null $router
	 *   The router to use, or null to build a fresh one on next use.
	 *
	 * @internal
	 */
	public static function useShared(?self $router): void
	{
		self::$shared = $router;
	}

	/**
	 * Splits a command line into words, or names why it cannot be run without a shell.
	 *
	 * @param string $line
	 *   The command line.
	 *
	 * @return array{argv: string[], refused: string|null}
	 *   The words, or an empty list and the reason.
	 */
	public static function tokenize(string $line): array
	{
		$argv = [];
		$word = '';
		$open = false;
		$quote = '';
		$chars = str_split($line);
		$count = count($chars);
		for ($i = 0; $i < $count; $i++) {
			$c = $chars[$i];
			if ($quote === "'") {
				if ($c === "'") {
					$quote = '';
				} else {
					$word .= $c;
				}
				continue;
			}
			if ($quote === '"') {
				if ($c === '"') {
					$quote = '';
				} elseif ($c === '$' || $c === '`') {
					return self::refusal('substitution and variable expansion need a shell');
				} elseif ($c === '\\' && $i + 1 < $count && str_contains('"\\$`', $chars[$i + 1])) {
					$word .= $chars[++$i];
				} else {
					$word .= $c;
				}
				continue;
			}
			switch ($c) {
				case "'":
				case '"':
					$quote = $c;
					$open = true;
					break;

				case '\\':
					if ($i + 1 < $count) {
						$word .= $chars[++$i];
						$open = true;
					}
					break;

				case ' ':
				case "\t":
					if ($open || $word !== '') {
						$argv[] = $word;
					}
					$word = '';
					$open = false;
					break;

				case '|':
					return self::refusal('pipes need a shell');

				case '<':
				case '>':
					return self::refusal('redirects need a shell');

				case '&':
					return self::refusal(
						($chars[$i + 1] ?? '') === '&'
							? 'command lists need a shell'
							: 'background jobs need a shell',
					);

				case ';':
				case "\n":
				case "\r":
					return self::refusal('command lists need a shell');

				case '`':
				case '$':
					return self::refusal('substitution and variable expansion need a shell');

				case '*':
					return self::refusal('globs need a shell');

				default:
					$word .= $c;
					$open = true;
			}
		}
		if ($quote !== '') {
			return self::refusal('the line has an unterminated quote');
		}
		if ($open || $word !== '') {
			$argv[] = $word;
		}
		return ['argv' => $argv, 'refused' => null];
	}

	/**
	 * Builds the answer for a line that needs a shell.
	 *
	 * @param string $reason
	 *   Why the line is refused.
	 *
	 * @return array{argv: string[], refused: string}
	 *   An empty word list and the reason.
	 */
	private static function refusal(string $reason): array
	{
		return ['argv' => [], 'refused' => $reason];
	}

	/**
	 * Runs one command.
	 *
	 * @param string|string[] $command
	 *   A line to tokenise, or an argv used as given.
	 * @param string $stdin
	 *   Bytes the program reads from standard input.
	 * @param string|null $cwd
	 *   Directory relative paths resolve against; null is the current directory.
	 * @param array<string, string> $env
	 *   Assignments visible to the program.
	 *
	 * @return array{stdout: string, stderr: string, code: int, program: string, outcome: string}
	 *   The output, exit code and counted outcome.
	 */
	public function run(
		string|array $command,
		string $stdin = '',
		?string $cwd = null,
		array $env = [],
	): array {
		if (is_string($command)) {
			$parsed = self::tokenize($command);
			if ($parsed['refused'] !== null) {
				return $this->finish('shell', 'refused', '', $parsed['refused'], 127);
			}
			$argv = $parsed['argv'];
		} else {
			$argv = array_values(array_map(strval(...), $command));
		}
		while ($argv !== [] && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $argv[0]) === 1) {
			[$name, $value] = explode('=', array_shift($argv), 2);
			$env[$name] = $value;
		}
		if ($argv === []) {
			return $this->finish('shell', 'ok', '', '', 0);
		}
		$program = basename(array_shift($argv));
		if (!in_array($program, self::PROGRAMS, true)) {
			return $this->finish($program, 'unknown', '', "$program: command not found", 127);
		}
		if (in_array($program, self::NETWORK, true) && $this->transport() === null) {
			return $this->finish(
				$program,
				'unavailable',
				'',
				"$program: this deployment has no outbound transport",
				127,
			);
		}

		$ctx = [
			'cwd' => $cwd ?? (string) getcwd(),
			'stdin' => $stdin,
			'env' => $env,
			'fetch' => $this->transport(),
		];
		try {
			[$out, $err, $code] = Commands::dispatch($program, $argv, $ctx);
		} catch (Refused $e) {
			return $this->finish($program, 'refused', '', $program . ': ' . $e->getMessage(), 127);
		} catch (Throwable $e) {
			return $this->finish($program, 'fail', '', $program . ': ' . $e->getMessage(), 1);
		}
		return $this->finish($program, $code === 0 ? 'ok' : 'fail', $out, $err, $code);
	}

	/**
	 * Whether the result is a launched program rather than a failed launch.
	 *
	 * @param array{outcome: string} $result
	 *   A result from run().
	 *
	 * @return bool
	 *   True when the program ran, whatever its exit code.
	 */
	public static function launched(array $result): bool
	{
		return $result['outcome'] === 'ok' || $result['outcome'] === 'fail';
	}

	/**
	 * Outcome counts for this boot, keyed `program:outcome`.
	 *
	 * @return array<string, int>
	 *   The counts.
	 */
	public static function counters(): array
	{
		return self::$counters;
	}

	/**
	 * Forgets the counts.
	 *
	 * @internal
	 */
	public static function resetCounters(): void
	{
		self::$counters = [];
	}

	/**
	 * The transport wget and curl use, or null.
	 *
	 * @return (callable(RequestInterface, array): PromiseInterface)|null
	 *   The transport, or null when this deployment has none.
	 */
	private function transport(): ?callable
	{
		if ($this->fetch !== null) {
			return $this->fetch;
		}
		return ParkFetchHandler::available() ? new ParkFetchHandler() : null;
	}

	/**
	 * Counts the outcome, records a failed launch, and builds the result.
	 *
	 * @param string $program
	 *   The program name.
	 * @param string $outcome
	 *   One of ok, fail, refused, unknown or unavailable.
	 * @param string $stdout
	 *   What the program printed.
	 * @param string $stderr
	 *   What it reported.
	 * @param int $code
	 *   The exit code.
	 *
	 * @return array{stdout: string, stderr: string, code: int, program: string, outcome: string}
	 *   The result.
	 */
	private function finish(
		string $program,
		string $outcome,
		string $stdout,
		string $stderr,
		int $code,
	): array {
		$key = $program . ':' . $outcome;
		self::$counters[$key] = (self::$counters[$key] ?? 0) + 1;
		if (!in_array($outcome, ['ok', 'fail'], true)) {
			Degradation::record('exec ' . $program, $stderr, 'blocked');
		}
		return [
			'stdout' => $stdout,
			'stderr' => $stderr === '' || str_ends_with($stderr, "\n") ? $stderr : $stderr . "\n",
			'code' => $code,
			'program' => $program,
			'outcome' => $outcome,
		];
	}
}
