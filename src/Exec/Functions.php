<?php

declare(strict_types=1);

namespace Drupal\drupflare\Exec;

use Drupal\drupflare\Degradation;

/**
 * The PHP process functions, answered by {@see Router}.
 *
 * The runtime removes the built-ins through `disable_functions` and declares each name as a thin
 * call into one of these. A command the router serves runs and reports its own exit code; a line it
 * refuses, or a program outside its table, is a failed launch: `false` and exit code 127.
 */
final class Functions
{
	/**
	 * Processes opened through procOpen(), keyed by the id of their handle.
	 *
	 * @var array<int, ProcHandle>
	 */
	private static array $procs = [];

	/**
	 * The last process id handed out.
	 *
	 * @var int
	 */
	private static int $pid = 1000;

	/**
	 * A pipe that never starts its command stops waiting for stdin after this many polls.
	 */
	private const STDIN_POLLS = 50;

	/**
	 * Runs a command like exec().
	 *
	 * @param string $command
	 *   The command line.
	 * @param array<string> $output
	 *   Receives each output line, appended.
	 * @param int $result_code
	 *   Receives the exit code.
	 *
	 * @param-out array<string> $output
	 * @param-out int $result_code
	 *
	 * @return string|false
	 *   The last output line, or false for a failed launch.
	 */
	public static function exec(
		string $command,
		&$output = null,
		&$result_code = null,
	): string|false {
		$result = Router::shared()->run($command);
		if (!Router::launched($result)) {
			$output = is_array($output) ? $output : [];
			$result_code = 127;
			return false;
		}
		$lines = self::lines($result['stdout']);
		$output = array_merge(is_array($output) ? $output : [], $lines);
		$result_code = $result['code'];
		return $lines === [] ? '' : $lines[count($lines) - 1];
	}

	/**
	 * Runs a command like shell_exec().
	 *
	 * @param string $command
	 *   The command line.
	 *
	 * @return string|false|null
	 *   The output, null when there is none, or false for a failed launch.
	 */
	public static function shellExec(string $command): string|false|null
	{
		$result = Router::shared()->run($command);
		if (!Router::launched($result)) {
			return false;
		}
		return $result['stdout'] === '' ? null : $result['stdout'];
	}

	/**
	 * Runs a command like system().
	 *
	 * @param string $command
	 *   The command line.
	 * @param int $result_code
	 *   Receives the exit code.
	 *
	 * @param-out int $result_code
	 *
	 * @return string|false
	 *   The last output line, or false for a failed launch.
	 */
	public static function system(string $command, &$result_code = null): string|false
	{
		$result = Router::shared()->run($command);
		if (!Router::launched($result)) {
			$result_code = 127;
			return false;
		}
		echo $result['stdout'];
		$result_code = $result['code'];
		$lines = self::lines($result['stdout']);
		return $lines === [] ? '' : $lines[count($lines) - 1];
	}

	/**
	 * Runs a command like passthru().
	 *
	 * @param string $command
	 *   The command line.
	 * @param int $result_code
	 *   Receives the exit code.
	 *
	 * @param-out int $result_code
	 *
	 * @return false|null
	 *   Null after running, or false for a failed launch.
	 */
	public static function passthru(string $command, &$result_code = null): ?false
	{
		$result = Router::shared()->run($command);
		if (!Router::launched($result)) {
			$result_code = 127;
			return false;
		}
		echo $result['stdout'];
		$result_code = $result['code'];
		return null;
	}

	/**
	 * Opens a read handle over a command's output.
	 *
	 * @param string $command
	 *   The command line.
	 * @param string $mode
	 *   Only read modes are served.
	 *
	 * @return resource|false
	 *   The handle, or false for a write mode or a failed launch.
	 */
	public static function popen(string $command, string $mode)
	{
		if (!str_starts_with($mode, 'r')) {
			Degradation::record(
				'popen write',
				'a write pipe would feed a child process, and there is none',
			);
			return false;
		}
		$result = Router::shared()->run($command);
		if (!Router::launched($result)) {
			return false;
		}
		$stream = fopen('php://temp', 'r+');
		if ($stream === false) {
			return false;
		}
		fwrite($stream, $result['stdout']);
		rewind($stream);
		return $stream;
	}

	/**
	 * Opens a process like proc_open().
	 *
	 * @param string|string[] $command
	 *   The command line or argv.
	 * @param array<int, mixed> $descriptor_spec
	 *   The descriptors, as proc_open() takes them.
	 * @param array<int, resource> $pipes
	 *   Receives the parent handles of the pipe descriptors.
	 * @param string|null $cwd
	 *   The working directory.
	 * @param array<string, string>|null $env_vars
	 *   The environment assignments.
	 * @param array<string, mixed>|null $options
	 *   Ignored; there is no process to configure.
	 *
	 * @param-out array<int, resource> $pipes
	 *
	 * @return resource|false
	 *   A process handle, or false for a descriptor that cannot be served.
	 */
	public static function procOpen(
		string|array $command,
		array $descriptor_spec,
		&$pipes = null,
		?string $cwd = null,
		?array $env_vars = null,
		?array $options = null,
	) {
		$pipes = [];
		$streams = [];
		$id = null;
		foreach ($descriptor_spec as $fd => $spec) {
			if (is_array($spec) && ($spec[0] ?? '') === 'pty') {
				Degradation::record('proc_open pty', 'there is no terminal to attach');
				return false;
			}
			if (!is_array($spec) || ($spec[0] ?? '') !== 'pipe') {
				continue;
			}
			$id ??= PipeStream::create();
			$handle = PipeStream::handle($id, (int) $fd);
			$pipes[$fd] = $handle;
			$streams[$fd] = $handle;
		}
		$anchor = fopen('php://memory', 'r');
		if ($anchor === false) {
			return false;
		}
		$proc = new ProcHandle(
			$command,
			$descriptor_spec,
			$streams,
			$id,
			$cwd,
			array_map(strval(...), $env_vars ?? []),
			++self::$pid,
		);
		self::$procs[(int) $anchor] = $proc;
		if (!isset($descriptor_spec[0][0]) || $descriptor_spec[0][0] !== 'pipe') {
			self::run($proc);
		}
		return $anchor;
	}

	/**
	 * Reports a process like proc_get_status().
	 *
	 * @param resource $process
	 *   The handle procOpen() returned.
	 *
	 * @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int}|false
	 *   The status, or false for a handle that is not a process.
	 */
	public static function procGetStatus($process): array|false
	{
		$proc = self::$procs[(int) $process] ?? null;
		if ($proc === null) {
			return false;
		}
		if (!$proc->ran && !$proc->terminated) {
			$proc->polls++;
			if (
				$proc->pipeId === null ||
				PipeStream::closed($proc->pipeId) ||
				$proc->polls >= self::STDIN_POLLS
			) {
				self::run($proc);
			}
		}
		$running = !$proc->ran && !$proc->terminated;
		$exit = $proc->ran && !$proc->reported ? $proc->exit : -1;
		$proc->reported = $proc->ran || $proc->reported;
		return [
			'command' => is_array($proc->command) ? implode(' ', $proc->command) : $proc->command,
			'pid' => $proc->pid,
			'running' => $running,
			'signaled' => $proc->terminated,
			'stopped' => false,
			'exitcode' => $exit,
			'termsig' => $proc->terminated ? 15 : 0,
			'stopsig' => 0,
		];
	}

	/**
	 * Closes a process like proc_close().
	 *
	 * @param resource $process
	 *   The handle procOpen() returned.
	 *
	 * @return int
	 *   The exit code, or -1.
	 */
	public static function procClose($process): int
	{
		$proc = self::$procs[(int) $process] ?? null;
		if ($proc === null) {
			return -1;
		}
		if (!$proc->ran && !$proc->terminated) {
			self::run($proc);
		}
		unset(self::$procs[(int) $process]);
		return $proc->terminated ? -1 : $proc->exit;
	}

	/**
	 * Terminates a process like proc_terminate().
	 *
	 * @param resource $process
	 *   The handle procOpen() returned.
	 * @param int $signal
	 *   Ignored beyond marking the process signalled.
	 *
	 * @return bool
	 *   True for a known handle.
	 */
	public static function procTerminate($process, int $signal = 15): bool
	{
		$proc = self::$procs[(int) $process] ?? null;
		if ($proc === null) {
			return false;
		}
		if (!$proc->ran) {
			$proc->terminated = true;
		}
		return true;
	}

	/**
	 * Closes a popen() handle; there is no exit code to report.
	 *
	 * @param resource $handle
	 *   The handle popen() returned.
	 *
	 * @return int
	 *   0 on success, -1 on failure.
	 */
	public static function pclose($handle): int
	{
		return fclose($handle) ? 0 : -1;
	}

	/**
	 * Runs the command and delivers its output to the descriptors.
	 *
	 * @param ProcHandle $proc
	 *   The process to run.
	 */
	private static function run(ProcHandle $proc): void
	{
		$stdin = '';
		if ($proc->pipeId !== null && ($proc->spec[0][0] ?? '') === 'pipe') {
			$stdin = PipeStream::takeInput($proc->pipeId);
		} elseif (is_array($proc->spec[0] ?? null) && ($proc->spec[0][0] ?? '') === 'file') {
			$stdin = (string) @file_get_contents((string) $proc->spec[0][1]);
		}
		$result = Router::shared()->run($proc->command, $stdin, $proc->cwd, $proc->env);
		$proc->ran = true;
		$proc->exit = $result['code'];
		$err = $result['stderr'];
		$out = $result['stdout'];
		if (self::redirected($proc->spec[2] ?? null)) {
			$out .= $err;
			$err = '';
		}
		self::deliver($proc, 1, $out);
		self::deliver($proc, 2, $err);
	}

	/**
	 * Whether a descriptor spec redirects to standard output.
	 *
	 * @param mixed $spec
	 *   The spec of the standard error descriptor.
	 *
	 * @return bool
	 *   True for a redirect to descriptor 1.
	 */
	private static function redirected(mixed $spec): bool
	{
		return is_array($spec) && ($spec[0] ?? '') === 'redirect' && ($spec[1] ?? null) === 1;
	}

	/**
	 * Hands one descriptor its bytes.
	 *
	 * @param ProcHandle $proc
	 *   The process.
	 * @param int $fd
	 *   The descriptor: 1 for stdout, 2 for stderr.
	 * @param string $bytes
	 *   What the command printed there.
	 */
	private static function deliver(ProcHandle $proc, int $fd, string $bytes): void
	{
		$spec = $proc->spec[$fd] ?? null;
		if (isset($proc->streams[$fd]) && $proc->pipeId !== null) {
			PipeStream::deliver($proc->pipeId, $fd, $bytes);
		} elseif (is_array($spec) && ($spec[0] ?? '') === 'file') {
			file_put_contents(
				(string) $spec[1],
				$bytes,
				str_starts_with((string) ($spec[2] ?? 'w'), 'a') ? FILE_APPEND : 0,
			);
		} elseif (is_resource($spec)) {
			fwrite($spec, $bytes);
		}
	}

	/**
	 * Splits output into right-trimmed lines like exec().
	 *
	 * @param string $stdout
	 *   The output.
	 *
	 * @return string[]
	 *   The lines, without the final empty one.
	 */
	private static function lines(string $stdout): array
	{
		if ($stdout === '') {
			return [];
		}
		return array_map(rtrim(...), explode("\n", rtrim($stdout, "\n")));
	}
}
