<?php

declare(strict_types=1);

namespace Drupal\drupflare\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\drupflare\Menu\NodeLinkAccess;
use Drupal\node\NodeInterface;
use Drupal\path_alias\PathAliasInterface;

/**
 * Invalidates the tag a menu link to a node carries, on what the link depends on.
 *
 * {@see NodeLinkAccess} replaces `node:N` on the link with this tag. The link follows the node's
 * viewability, which is its status and owner in each translation, and its URL, which is its alias.
 */
final class NodeLinkTag
{
	/**
	 * Implements hook_node_update().
	 */
	#[Hook('node_update')]
	public function nodeUpdate(NodeInterface $node): void
	{
		$before = $node->getOriginal();
		if (!($before instanceof NodeInterface) || self::viewabilityMoved($before, $node)) {
			Cache::invalidateTags([NodeLinkAccess::TAG . $node->id()]);
		}
	}

	/**
	 * Implements hook_node_delete().
	 */
	#[Hook('node_delete')]
	public function nodeDelete(NodeInterface $node): void
	{
		Cache::invalidateTags([NodeLinkAccess::TAG . $node->id()]);
	}

	/**
	 * Implements hook_path_alias_insert().
	 */
	#[Hook('path_alias_insert')]
	public function aliasInsert(PathAliasInterface $alias): void
	{
		self::aliasMoved($alias);
	}

	/**
	 * Implements hook_path_alias_update().
	 */
	#[Hook('path_alias_update')]
	public function aliasUpdate(PathAliasInterface $alias): void
	{
		self::aliasMoved($alias);
		$before = $alias->getOriginal();
		if ($before instanceof PathAliasInterface) {
			self::aliasMoved($before);
		}
	}

	/**
	 * Implements hook_path_alias_delete().
	 */
	#[Hook('path_alias_delete')]
	public function aliasDelete(PathAliasInterface $alias): void
	{
		self::aliasMoved($alias);
	}

	/**
	 * Whether a save changed who may view the node in any translation.
	 */
	public static function viewabilityMoved(NodeInterface $before, NodeInterface $after): bool
	{
		$languages = array_unique(
			array_merge(
				array_keys($before->getTranslationLanguages()),
				array_keys($after->getTranslationLanguages()),
			),
		);
		foreach ($languages as $langcode) {
			if (!$before->hasTranslation($langcode) || !$after->hasTranslation($langcode)) {
				return true;
			}
			$old = $before->getTranslation($langcode);
			$new = $after->getTranslation($langcode);
			if (
				$old->isPublished() !== $new->isPublished() ||
				$old->getOwnerId() !== $new->getOwnerId()
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Invalidates the link tag of the node an alias points at, if it points at one.
	 */
	private static function aliasMoved(PathAliasInterface $alias): void
	{
		if (preg_match('#^/node/(\d+)$#', $alias->getPath(), $m) === 1) {
			Cache::invalidateTags([NodeLinkAccess::TAG . $m[1]]);
		}
	}
}
