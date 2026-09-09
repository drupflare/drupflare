<?php

declare(strict_types=1);

namespace Drupal\drupflare\Http;

/**
 * The one call that suspends PHP and lets the Worker answer it.
 *
 * `ext/cfwpark` traps INTERNAL functions, so a yield has to be one. There is no `cfw_park_yield()`
 * and adding one would mean another interpreter build for something an existing trap already does,
 * so `stream_socket_client()` carries it: the host dispatches on the scheme, and a
 * `cfwpark+fetch://` target is answered with a whole HTTP response rather than a stream.
 *
 * **THE TRAP CHANGES THE RETURN TYPE, AND NO STUB CAN SAY SO.** `stream_socket_client()` is declared
 * `resource|false`, which is what it returns when nothing has trapped it; under the trap it returns
 * whatever the host resumed the call with. Calling it directly here makes static analysis prove that
 * `is_string()` on the result is always false -- correct about the declaration and wrong about the
 * runtime. So the call is dynamic, which is not a way of hiding the analyser but the accurate
 * statement: which function runs, and what it returns, is decided at runtime by the trap table.
 */
final class Park
{
	/**
	 * Suspends this call until the host answers it.
	 *
	 * @param string $target
	 *   The target, whose scheme tells the host what to perform.
	 *
	 * @return mixed
	 *   Whatever the host resumed with; a JSON string for a `cfwpark+fetch://` target. Returns a
	 *   stream resource or FALSE when no trap is armed, which is the real function answering.
	 */
	public static function yieldTo(string $target): mixed
	{
		$open = 'stream_socket_client';
		return @$open($target);
	}

	/**
	 * Why a park at this point would be refused, NAMED rather than counted.
	 *
	 * This mechanism has been misdiagnosed three times from a count alone: the frame was taken for
	 * `fopen`, then for Guzzle's `StreamHandler`, then for a `call_user_func_array` in a shape no
	 * harness reproduced. `cfw_park_frames()` reports each internal frame and whether the park can
	 * splice it, so the next refusal explains itself.
	 *
	 * @return array
	 *   `unsafe` counts the frames that refuse and `frames` names them. `unsafe` is -1 on a build
	 *   with no `cfw_park_frames()`, which is a different answer from 0 and must not read as one.
	 */
	public static function refusal(): array
	{
		if (!function_exists('cfw_park_frames')) {
			return ['unsafe' => -1, 'frames' => []];
		}
		$blocking = [];
		foreach (cfw_park_frames() as $frame) {
			if (($frame['transparent'] ?? false) !== true) {
				$blocking[] = (string) ($frame['fn'] ?? '?');
			}
		}
		return ['unsafe' => count($blocking), 'frames' => $blocking];
	}
}
