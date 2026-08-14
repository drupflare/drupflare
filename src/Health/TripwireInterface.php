<?php

namespace Drupal\drupflare\Health;

/**
 * A single health check that asserts a SYMPTOM rather than a cause.
 *
 * Every implementation of this interface corresponds to a defect this project has already shipped
 * and then found. That is the selection criterion: a tripwire earns its place by having caught
 * something real, and one nobody has seen fire is decoration.
 *
 * Two rules bind every implementation:
 *
 * - It must be O(1) or explicitly bounded. No full-table scans on the request path.
 * - It must not repair anything. Detection and repair are separated so a repair can be gated,
 *   rate-limited and escalated independently of what noticed.
 */
interface TripwireInterface
{
	/**
	 * The stable dotted code this tripwire reports under.
	 *
	 * @return string
	 *   For example "db.txn_leaked".
	 */
	public function code(): string;

	/**
	 * Evaluates the check.
	 *
	 * @param array $observation
	 *   Scalars the caller already had. A tripwire must not go looking for more, because that is
	 *   how an O(1) check becomes a query on the request path.
	 *
	 * @return Finding|null
	 *   A finding, or NULL when the check is satisfied.
	 */
	public function check(array $observation): ?Finding;
}
