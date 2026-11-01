<?php

declare(strict_types=1);

namespace Drupal\drupflare\Form;

use Drupal\Component\Utility\Html;

/**
 * The form element for one numerically bound runtime lever.
 *
 * The real field is a plain number input: empty defers to the deployed value, and a typed value is
 * the override. `js/lever-slider.js` adds a slider above it, with named layers where the host
 * reports marks. Everything is also rendered server side, so the page informs and submits with
 * JavaScript off.
 *
 * Nothing here reads the container, so any form can reuse it and the health suite can assert it.
 * Marks and the default come from the host's domain or not at all; this class never supplies
 * either.
 */
final class LeverSlider
{
	/**
	 * Builds the element for one lever.
	 *
	 * @param array $domain
	 *   The lever's domain as the host reports it: `min`, `max`, optional `unit`, `default` and
	 *   `marks`, each mark carrying `value`, `label` and `note`.
	 * @param string $override
	 *   The stored override, or '' when the value in force comes from elsewhere.
	 * @param string $deployed
	 *   The value in force without an override, or '' when unknown.
	 * @param bool $writable
	 *   False on a read-only deployment, which disables the field and the slider.
	 *
	 * @return array
	 *   A number element with its wrapper, description bar and mark list.
	 */
	public static function element(
		array $domain,
		string $override,
		string $deployed,
		bool $writable,
	): array {
		$min = (int) ($domain['min'] ?? 0);
		$max = (int) ($domain['max'] ?? 0);
		$unit = in_array($domain['unit'] ?? null, ['ms', 'bytes'], true) ? $domain['unit'] : '';
		$default =
			isset($domain['default']) && is_numeric($domain['default'])
				? (string) (int) $domain['default']
				: '';
		$marks = self::marks($domain['marks'] ?? [], $min, $max);

		$attributes = [
			'data-drupflare-slider' => '',
			'data-min' => (string) $min,
			'data-max' => (string) $max,
		];
		if ($unit !== '') {
			$attributes['data-unit'] = $unit;
		}
		if ($default !== '') {
			$attributes['data-default'] = $default;
		}
		if ($deployed !== '' && is_numeric($deployed)) {
			$attributes['data-deployed'] = $deployed;
		}
		if ($marks !== []) {
			$attributes['data-marks'] = json_encode(
				$marks,
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
			);
		}

		return [
			'#type' => 'number',
			'#default_value' => $override,
			'#disabled' => !$writable,
			'#placeholder' => $deployed,
			'#min' => $min,
			'#max' => $max,
			'#step' => 1,
			'#field_suffix' => $unit,
			'#attributes' => $attributes,
			'#prefix' => '<div class="drupflare-lever-slider">',
			'#suffix' =>
				self::bar($min, $max, $unit, $default) . self::list($marks, $unit) . '</div>',
			'#attached' => ['library' => ['drupflare/lever-slider']],
		];
	}

	/**
	 * Keeps the marks that can be placed on the track.
	 *
	 * @param mixed $raw
	 *   What the host sent.
	 * @param int $min
	 *   The domain minimum.
	 * @param int $max
	 *   The domain maximum.
	 *
	 * @return array
	 *   Marks with a numeric value inside the range, in the order given.
	 */
	private static function marks(mixed $raw, int $min, int $max): array
	{
		$marks = [];
		foreach (is_array($raw) ? $raw : [] as $mark) {
			if (!is_array($mark) || !isset($mark['value']) || !is_numeric($mark['value'])) {
				continue;
			}
			$value = (int) $mark['value'];
			if ($value < $min || $value > $max) {
				continue;
			}
			$marks[] = [
				'value' => $value,
				'label' => (string) ($mark['label'] ?? ''),
				'note' => (string) ($mark['note'] ?? ''),
			];
		}
		return $marks;
	}

	/**
	 * The description bar as the page shows it before any script runs.
	 *
	 * @param int $min
	 *   The domain minimum.
	 * @param int $max
	 *   The domain maximum.
	 * @param string $unit
	 *   One of ms or bytes, or '' for none.
	 * @param string $default
	 *   The default, or '' when the host reports none.
	 *
	 * @return string
	 *   Markup built from numbers and fixed text only.
	 */
	private static function bar(int $min, int $max, string $unit, string $default): string
	{
		$text = 'Range ' . self::amount($min, $unit) . ' to ' . self::amount($max, $unit) . '.';
		if ($default !== '') {
			$text .= ' Default ' . self::amount((int) $default, $unit) . '.';
		}
		return '<p class="drupflare-lever-slider__bar" aria-live="polite">' .
			Html::escape($text) .
			'</p>';
	}

	/**
	 * The marks as a static ordered list, the no-script form of the track labels.
	 *
	 * @param array $marks
	 *   The placed marks.
	 * @param string $unit
	 *   One of ms or bytes, or '' for none.
	 *
	 * @return string
	 *   Markup with every host-supplied string escaped, or '' with no marks.
	 */
	private static function list(array $marks, string $unit): string
	{
		if ($marks === []) {
			return '';
		}
		$items = '';
		foreach ($marks as $mark) {
			$items .=
				'<li><strong>' .
				Html::escape($mark['label']) .
				'</strong> ' .
				Html::escape(self::amount($mark['value'], $unit)) .
				($mark['note'] === '' ? '' : '. ' . Html::escape($mark['note'])) .
				'</li>';
		}
		return '<ol class="drupflare-lever-slider__marks">' . $items . '</ol>';
	}

	/**
	 * A number with its unit.
	 *
	 * @param int $value
	 *   The number.
	 * @param string $unit
	 *   One of ms or bytes, or '' for none.
	 *
	 * @return string
	 *   For example `8000 ms`.
	 */
	private static function amount(int $value, string $unit): string
	{
		$text = number_format($value);
		return $unit === '' ? $text : $text . ' ' . $unit;
	}
}
