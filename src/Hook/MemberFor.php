<?php

declare(strict_types=1);

namespace Drupal\drupflare\Hook;

use Drupal;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;

/**
 * Renders a user page's "Member for" interval per request instead of freezing it in the cache.
 *
 * Core builds the element with no max-age, so the render cache and the dynamic page cache keep the
 * first interval they formatted, on any host. A placeholder keeps the page cached and renders only
 * the interval, which is the whole of what goes stale.
 */
final class MemberFor implements TrustedCallbackInterface
{
	/**
	 * Implements hook_user_view_alter().
	 */
	#[Hook('user_view_alter')]
	public function userViewAlter(
		array &$build,
		UserInterface $account,
		EntityViewDisplayInterface $display,
	): void {
		if (!isset($build['member_for'])) {
			return;
		}
		$element = [
			'#lazy_builder' => [self::class . '::interval', [(int) $account->getCreatedTime()]],
			'#create_placeholder' => true,
		];
		if (isset($build['member_for']['#weight'])) {
			$element['#weight'] = $build['member_for']['#weight'];
		}
		$build['member_for'] = $element;
	}

	/**
	 * The element core builds, formatted now and cached nowhere.
	 *
	 * @param int $created
	 *   The account's creation time.
	 *
	 * @return array
	 *   A render array.
	 */
	public static function interval(int $created): array
	{
		return [
			'#type' => 'item',
			'#markup' =>
				'<h4 class="label">' .
				new TranslatableMarkup('Member for') .
				'</h4> ' .
				Drupal::service('date.formatter')->formatTimeDiffSince($created),
			'#cache' => ['max-age' => 0],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public static function trustedCallbacks(): array
	{
		return ['interval'];
	}
}
