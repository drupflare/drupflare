<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use RuntimeException;

/**
 * Thrown when a shimmed function cannot be served, carrying WHAT and WHY.
 *
 * THIS IS THE POINT OF THE WHOLE LAYER. A silent empty return is indistinguishable from a real
 * empty result: `curl_exec()` returning FALSE reads as "the server said nothing", and
 * `openssl_digest()` returning FALSE reads as "unsupported algorithm". Both are what a caller sees
 * when the truth is "no sockets here" or "crypto.subtle has no md5". That ambiguity is this
 * project's signature failure shape, and it is why every refusal here names itself instead.
 *
 * The message always contains the function name and a reason, so a log line is enough to diagnose
 * without a debugger -- which matters because there is no debugger on the edge.
 */
final class ShimRefusal extends RuntimeException
{
	/**
	 * The function that was refused.
	 */
	private string $shimFunction;

	/**
	 * The reason, without the function name prefix.
	 */
	private string $shimReason;

	/**
	 * Builds the refusal, assembling the message so every caller words it the same way.
	 *
	 * @param string $function
	 *   The PHP function that was asked for.
	 * @param string $reason
	 *   Why it cannot be served, in one sentence.
	 * @param string $alternative
	 *   What to use instead, or an empty string when there is nothing.
	 */
	public function __construct(string $function, string $reason, string $alternative = '')
	{
		$this->shimFunction = $function;
		$this->shimReason = $reason;
		$message = sprintf('%s() refused: %s', $function, $reason);
		if ($alternative !== '') {
			$message .= sprintf(' Use %s instead.', $alternative);
		}
		parent::__construct($message);
	}

	/**
	 * The refused function's name.
	 *
	 * @return string
	 *   The name as it was asked for.
	 */
	public function functionName(): string
	{
		return $this->shimFunction;
	}

	/**
	 * The reason on its own, for a caller assembling its own message.
	 *
	 * @return string
	 *   Never empty; a refusal without a reason is the thing this class exists to prevent.
	 */
	public function reason(): string
	{
		return $this->shimReason;
	}
}
