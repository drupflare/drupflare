<?php

namespace Drupal\drupflare\Health;

/**
 * Escalates a repeating failure and decays a quiet one.
 *
 * The PHP half mirrors src/ops/supervisor.ts so a repair decided on either side of the bridge
 * lands on the same rung.
 */
final class CircuitBreaker
{
	/**
	 * Window inside which repeats count toward escalation, in milliseconds.
	 *
	 * @var int
	 */
	private int $windowMs;

	/**
	 * Repeats inside the window that escalate one rung.
	 *
	 * @var int
	 */
	private int $threshold;

	/**
	 * Per-code state: hits and current rung.
	 *
	 * @var array
	 */
	private array $state = [];

	/**
	 * Constructs the breaker.
	 *
	 * @param int $window_ms
	 *   Window in milliseconds.
	 * @param int $threshold
	 *   Repeats that escalate.
	 */
	public function __construct(int $window_ms = 60000, int $threshold = 3)
	{
		$this->windowMs = $window_ms;
		$this->threshold = $threshold;
	}

	/**
	 * Records a firing and returns the rung to act at.
	 *
	 * @param string $code
	 *   The finding's code.
	 * @param int $severity
	 *   One of the Finding severity ordinals.
	 * @param int $now_ms
	 *   Caller-supplied clock, because in-PHP time returns 0 on the edge.
	 *
	 * @return string
	 *   A rung name from RepairLadder::RUNGS.
	 */
	public function record(string $code, int $severity, int $now_ms): string
	{
		$entry = $this->state[$code] ?? [
			'hits' => [],
			'rung' => RepairLadder::initialRung($severity),
		];
		$window = $this->windowMs;
		$entry['hits'] = array_values(
			array_filter($entry['hits'], static fn(int $t): bool => $now_ms - $t < $window),
		);
		$entry['hits'][] = $now_ms;
		if (count($entry['hits']) >= $this->threshold) {
			$entry['rung'] = RepairLadder::escalate($entry['rung']);
			$entry['hits'] = [];
		}
		$this->state[$code] = $entry;
		return $entry['rung'];
	}

	/**
	 * Decays every code one rung after a clean interval.
	 */
	public function decay(): void
	{
		foreach ($this->state as $code => $entry) {
			$next = RepairLadder::decay($entry['rung']);
			if ($next === null) {
				unset($this->state[$code]);
				continue;
			}
			$this->state[$code]['rung'] = $next;
		}
	}

	/**
	 * The rung a code currently sits at.
	 *
	 * @param string $code
	 *   The finding's code.
	 *
	 * @return string|null
	 *   The rung, or NULL when the code is not being tracked.
	 */
	public function rungOf(string $code): ?string
	{
		return $this->state[$code]['rung'] ?? null;
	}
}
