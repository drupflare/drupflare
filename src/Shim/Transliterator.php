<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\drupflare\Degradation;

/**
 * The Transliterator class for the one rule family Drupal code asks ICU for, over core's tables.
 *
 * The build carries no ext-intl, and the ICU data behind a general transliterator is too large to
 * ship. What contrib code actually calls is `Any-Latin; Latin-ASCII`, optionally followed by
 * `Lower()`, `Upper()` or `[:Nonspacing Mark:] Remove`, to turn a title into ASCII. Core's
 * `PhpTransliteration` produces exactly that output from its own data files. Every other rule is
 * refused by name, and `create()` answers NULL the way ICU does for an ID it does not know.
 */
class Transliterator
{
	public const FORWARD = 0;
	public const REVERSE = 1;

	public readonly string $id;

	/**
	 * The folding step after transliteration: '', 'lower' or 'upper'.
	 */
	private string $fold;

	private static ?PhpTransliteration $engine = null;

	final private function __construct(string $id, string $fold)
	{
		$this->id = $id;
		$this->fold = $fold;
	}

	public static function create(string $id, int $direction = self::FORWARD): ?static
	{
		$fold = self::parse($id);
		if ($fold === null || $direction !== self::FORWARD) {
			Degradation::record(
				'Transliterator ' . $id,
				'only Any-Latin; Latin-ASCII (with an optional Lower() or Upper()) is available without ext-intl',
			);
			return null;
		}
		return new static($id, $fold);
	}

	/**
	 * Lists the rule ids this class serves.
	 *
	 * @return string[]
	 *   The ids.
	 */
	public static function listIDs(): array
	{
		return ['Any-Latin; Latin-ASCII', 'Latin-ASCII'];
	}

	public function transliterate(string $string, int $start = 0, int $end = -1): string|false
	{
		$head = substr($string, 0, $start);
		$body = $end < 0 ? substr($string, $start) : substr($string, $start, $end - $start);
		$tail = $end < 0 ? '' : substr($string, $end);
		self::$engine ??= new PhpTransliteration();
		$out = self::$engine->transliterate($body, 'en', '?');
		$out = match ($this->fold) {
			'lower' => strtolower($out),
			'upper' => strtoupper($out),
			default => $out,
		};
		return $head . $out . $tail;
	}

	public function getErrorCode(): int
	{
		return 0;
	}

	public function getErrorMessage(): string
	{
		return 'U_ZERO_ERROR';
	}

	/**
	 * The fold a supported rule ends with, or NULL for a rule this class cannot honour.
	 */
	private static function parse(string $id): ?string
	{
		$fold = '';
		$steps = array_values(
			array_filter(
				array_map('trim', explode(';', $id)),
				static fn(string $s): bool => $s !== '',
			),
		);
		$latin = false;
		foreach ($steps as $step) {
			$normalized = strtolower(str_replace(' ', '', $step));
			if (
				in_array(
					$normalized,
					['any-latin', 'latin-ascii', 'any-ascii', 'nfd', 'nfc', 'nfkd', 'nfkc'],
					true,
				)
			) {
				$latin = $latin || str_ends_with($normalized, 'ascii');
				continue;
			}
			if (in_array($normalized, ['[:nonspacingmark:]remove', '[:mn:]remove'], true)) {
				continue;
			}
			if (in_array($normalized, ['lower()', 'any-lower'], true)) {
				$fold = 'lower';
				continue;
			}
			if (in_array($normalized, ['upper()', 'any-upper'], true)) {
				$fold = 'upper';
				continue;
			}
			return null;
		}
		return $latin ? $fold : null;
	}
}
