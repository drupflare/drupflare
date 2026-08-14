<?php

namespace Drupal\drupflare\Health;

/**
 * One thing a tripwire noticed.
 *
 * A value object rather than an array so a typo in a key is a compile-time error instead of a
 * silently absent field. The host half in src/ops/supervisor.ts carries the same four fields.
 */
final class Finding
{
	/**
	 * Severity ordinals, matching the host half and the ledger column.
	 */
	const INFO = 0;
	const WARN = 1;
	const ERROR = 2;
	const CRITICAL = 3;

	/**
	 * Longest context this will carry into the ledger.
	 *
	 * An unbounded column is how a log table becomes 46% of the database, which is what the
	 * watchdog table did before anybody looked.
	 */
	const MAX_CONTEXT = 400;

	/**
	 * Stable dotted identifier; the circuit breaker keys on this.
	 *
	 * @var string
	 */
	public string $code;

	/**
	 * One of the severity ordinals.
	 *
	 * @var int
	 */
	public int $severity;

	/**
	 * What the finding was about: a bin, a table, a path.
	 *
	 * @var string
	 */
	public string $scope;

	/**
	 * Short human-readable detail, truncated on construction.
	 *
	 * @var string
	 */
	public string $context;

	/**
	 * Constructs a finding.
	 *
	 * @param string $code
	 *   Stable dotted identifier.
	 * @param int $severity
	 *   One of the severity ordinals on this class.
	 * @param string $scope
	 *   What it was about.
	 * @param string $context
	 *   Detail; truncated to MAX_CONTEXT.
	 */
	public function __construct(
		string $code,
		int $severity,
		string $scope = '',
		string $context = '',
	) {
		$this->code = $code;
		$this->severity = $severity;
		$this->scope = substr($scope, 0, 120);
		$this->context = substr($context, 0, self::MAX_CONTEXT);
	}

	/**
	 * Renders the finding as the array the host bridge carries.
	 *
	 * @return array
	 *   Keys code, severity, scope, context.
	 */
	public function toArray(): array
	{
		return [
			'code' => $this->code,
			'severity' => $this->severity,
			'scope' => $this->scope,
			'context' => $this->context,
		];
	}
}
