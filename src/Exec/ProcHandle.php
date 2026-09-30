<?php

declare(strict_types=1);

namespace Drupal\drupflare\Exec;

/**
 * One process opened through {@see Functions::procOpen()}.
 *
 * The command has not run until the first status poll that finds its stdin closed, or until
 * {@see Functions::procClose()}.
 */
final class ProcHandle
{
	/**
	 * Whether the command has run.
	 *
	 * @var bool
	 */
	public bool $ran = false;

	/**
	 * Whether the exit code has been reported once.
	 *
	 * @var bool
	 */
	public bool $reported = false;

	/**
	 * The exit code, or -1 before the run.
	 *
	 * @var int
	 */
	public int $exit = -1;

	/**
	 * Status polls taken while waiting on stdin.
	 *
	 * @var int
	 */
	public int $polls = 0;

	/**
	 * Whether proc_terminate() ended the process before it ran.
	 *
	 * @var bool
	 */
	public bool $terminated = false;

	/**
	 * Records an opened process.
	 *
	 * @param string|string[] $command
	 *   The command line or argv.
	 * @param array<int, mixed> $spec
	 *   The descriptor spec proc_open() received.
	 * @param array<int, resource> $streams
	 *   The parent handles of the pipe descriptors.
	 * @param int|null $pipeId
	 *   The id of the pipe set, or null with no pipes.
	 * @param string|null $cwd
	 *   The working directory.
	 * @param array<string, string> $env
	 *   The environment assignments.
	 * @param int $pid
	 *   The process id reported by proc_get_status().
	 */
	public function __construct(
		public string|array $command,
		public array $spec,
		public array $streams,
		public ?int $pipeId,
		public ?string $cwd,
		public array $env,
		public int $pid,
	) {}
}
