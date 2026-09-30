<?php

declare(strict_types=1);

namespace Drupal\drupflare\Ops;

/**
 * Runs advancedqueue jobs by count rather than by time.
 *
 * `Processor::processQueue()` loops until its time limit, and the clock does not advance inside a
 * PHP run here, so a queue with the default settings would never return. A call runs at most
 * `$limit` jobs across the queues it names and reports what is left, so the host can repeat it on
 * the alarm until nothing remains.
 *
 * The advancedqueue calls arrive as closures so this stays testable without the module.
 */
final class QueueDrain
{
	public const DEFAULT_LIMIT = 10;

	public const MAX_LIMIT = 50;

	/**
	 * Runs jobs across the named queues, up to the limit.
	 *
	 * @param string[] $queues
	 *   Queue ids, in the order to drain them.
	 * @param int $limit
	 *   The most jobs to run in this call.
	 * @param callable(string): ?object $claim
	 *   Claims the next job of a queue, or null when it has none.
	 * @param callable(object, string): mixed $process
	 *   Runs one claimed job to its result.
	 * @param callable(string): int $remaining
	 *   How many jobs a queue still holds in the queued state.
	 *
	 * @return array{ok: bool, done: bool, processed: int, remaining: int, queues: array<string, array{processed: int, remaining: int}>}
	 *   What ran and what is left, per queue and in total.
	 */
	public static function run(
		array $queues,
		int $limit,
		callable $claim,
		callable $process,
		callable $remaining,
	): array {
		$left = max(1, min($limit, self::MAX_LIMIT));
		$report = [];
		$processed = 0;
		foreach ($queues as $id) {
			$count = 0;
			while ($left > 0 && ($job = $claim($id)) !== null) {
				$process($job, $id);
				$count++;
				$left--;
			}
			$report[$id] = ['processed' => $count, 'remaining' => 0];
			$processed += $count;
		}
		$total = 0;
		foreach ($queues as $id) {
			$report[$id]['remaining'] = $remaining($id);
			$total += $report[$id]['remaining'];
		}
		return [
			'ok' => true,
			'done' => $total === 0,
			'processed' => $processed,
			'remaining' => $total,
			'queues' => $report,
		];
	}
}
