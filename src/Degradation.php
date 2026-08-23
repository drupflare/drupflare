<?php

declare(strict_types=1);

namespace Drupal\drupflare;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A capability that is degraded, recorded so it can never be SILENTLY degraded.
 *
 * P45's rule, and it is a product position rather than a coding style: an unmodified module is the
 * whole claim, so every gap is the host's to close or to DECLARE. A capability is therefore in
 * exactly one of three states -- shimmed, accommodated, or declared -- and "quietly does nothing"
 * is not one of them.
 *
 * The failure this exists to prevent has two instances in this codebase already. `strata_files`
 * captured nothing because a stream wrapper answered FALSE by design, and the whole supervisor was
 * green in CI while imported by nothing under `src/`. Both were correct code that reported success
 * and delivered nothing, which is the worst shape a defect can take because no test fails.
 *
 * A declared degradation must do three things and this class does all three:
 *   1. it CANNOT fatal -- recording is a static array write and nothing here throws;
 *   2. it logs ONCE PER BOOT at warn, naming the capability and the caller;
 *   3. it surfaces a status-report row, in the `verified`/`untested`/`blocked` vocabulary the
 *      module table already uses, so an operator sees it without reading a log.
 *
 * ONCE PER BOOT, not once per call, and the distinction is the point. A degraded function reached
 * inside a render loop would otherwise write thousands of identical rows into `watchdog` and spend
 * the meter that binds regeneration -- turning a diagnostic into an outage.
 */
final class Degradation
{
	/**
	 * The three states a capability may be in.
	 *
	 * Deliberately the module table's words rather than new ones. `supported` is absent here for
	 * the same reason it is absent there: it meant "the capability was measured WITHOUT the thing
	 * that needs it", which is an inference about the runtime that reads as a promise about the
	 * feature.
	 */
	public const STATES = ['verified', 'untested', 'blocked'];

	/**
	 * What has been declared this boot, keyed by capability so a repeat is not a second entry.
	 *
	 * @var array<string, array{capability: string, reason: string, caller: string, state: string}>
	 */
	private static array $declared = [];

	/**
	 * Records a degraded capability, at most once per capability per boot.
	 *
	 * @param string $capability
	 *   The function or feature that is degraded, e.g. `sodium_crypto_generichash`.
	 * @param string $reason
	 *   Why, in terms an operator can act on.
	 * @param string $state
	 *   One of {@see self::STATES}; anything else is recorded as `blocked`, because an unknown
	 *   state must not read as a weaker claim than it is.
	 */
	public static function record(
		string $capability,
		string $reason,
		string $state = 'blocked',
	): void {
		if (isset(self::$declared[$capability])) {
			return;
		}

		self::$declared[$capability] = [
			'capability' => $capability,
			'reason' => $reason,
			'caller' => self::caller(),
			'state' => in_array($state, self::STATES, true) ? $state : 'blocked',
		];

		// the log is best-effort by construction: a degradation reported during boot may precede
		// the logger, and a declaration that fataled would be worse than the gap it describes
		try {
			Host::call('cfwLog', [
				// both, deliberately: `phpLogPasses()` prefers the number and falls back to the
				// name, so sending each makes the ceiling filter independent of the lookup table
				'level' => 'warn',
				'severity' => 4,
				'channel' => 'drupflare',
				'timestamp' => (string) time(),
				'message' => sprintf(
					'drupflare: %s is degraded (%s), first reached from %s',
					$capability,
					$reason,
					self::$declared[$capability]['caller'],
				),
			]);
		} catch (\Throwable) {
			// deliberately swallowed; the status-report row below is the durable record
		}
	}

	/**
	 * Everything declared this boot.
	 *
	 * @return array<string, array{capability: string, reason: string, caller: string, state: string}>
	 *   Keyed by capability.
	 */
	public static function all(): array
	{
		return self::$declared;
	}

	/**
	 * Whether a capability has been declared degraded.
	 */
	public static function isDeclared(string $capability): bool
	{
		return isset(self::$declared[$capability]);
	}

	/**
	 * Forgets everything, for tests only.
	 *
	 * @internal
	 */
	public static function reset(): void
	{
		self::$declared = [];
	}

	/**
	 * The status-report rows, one per declared degradation.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Keyed the way `hook_runtime_requirements()` wants.
	 */
	public static function requirements(): array
	{
		$rows = [];
		foreach (self::$declared as $capability => $entry) {
			$rows['drupflare_degraded_' . preg_replace('/[^a-z0-9_]+/i', '_', $capability)] = [
				'title' => new TranslatableMarkup('Drupflare: @capability', [
					'@capability' => $capability,
				]),
				'value' => new TranslatableMarkup('@state', ['@state' => $entry['state']]),
				'description' => new TranslatableMarkup('@reason (first reached from @caller)', [
					'@reason' => $entry['reason'],
					'@caller' => $entry['caller'],
				]),
				// `blocked` is an Error because the feature does not work; `untested` is a
				// Warning because nothing has proven it either way
				'severity' =>
					$entry['state'] === 'blocked'
						? RequirementSeverity::Error
						: RequirementSeverity::Warning,
			];
		}
		return $rows;
	}

	/**
	 * The first frame outside this class, which is the code that hit the gap.
	 *
	 * `DEBUG_BACKTRACE_IGNORE_ARGS` because an argument list here could hold file bytes or a
	 * password, and this string ends up in a log and on the status report.
	 */
	private static function caller(): string
	{
		$frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

		// A frame's file/line is where the call came FROM; its function/class is what was called.
		// So the call SITE is on the last frame belonging to this class, and the calling FUNCTION
		// is on the first frame that is not -- two different frames, and reading both off one is
		// what made this return 'unknown' for every call made from file scope.
		$site = null;
		$named = null;
		foreach ($frames as $frame) {
			if (($frame['class'] ?? '') === self::class) {
				$site = $frame;
				continue;
			}
			$named = $frame;
			break;
		}

		$where =
			$site === null
				? ''
				: basename((string) ($site['file'] ?? '?')) . ':' . ($site['line'] ?? 0);

		if ($named === null) {
			// called straight from file scope, which has no enclosing function to name
			return $where === '' ? 'unknown' : $where;
		}
		$class = $named['class'] ?? '';
		$fn = ($class !== '' ? $class . '::' : '') . ($named['function'] ?? '?');
		return $where === '' ? $fn : $fn . ' at ' . $where;
	}
}
