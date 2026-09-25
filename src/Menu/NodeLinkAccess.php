<?php

declare(strict_types=1);

namespace Drupal\drupflare\Menu;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Menu\DefaultMenuLinkTreeManipulators;
use Drupal\Core\Menu\MenuLinkInterface;

/**
 * Checks a menu link to a node without tying the page to every save of that node.
 *
 * A menu block checks each link through its route, and `node.view` access adds the node as a
 * cacheable dependency, so every page carrying the main menu took `node:N` for each linked node
 * and a title edit re-rendered all of them. What a link to a node depends on is narrower: whether
 * it is viewable, which follows its status and owner, and its alias. `NodeLinkTag` invalidates
 * {@see self::TAG} on exactly those.
 *
 * Narrowed only while view access cannot depend on anything else: no node grants, and no
 * `node_access` or `entity_access` implementation outside the core ones whose view answer ignores
 * the node's fields. Any other module keeps core's tag.
 */
final class NodeLinkAccess extends DefaultMenuLinkTreeManipulators
{
	public const TAG = 'drupflare_node_link:';

	/**
	 * Implementations known to answer `view` without reading a node's fields.
	 */
	private const FIELD_BLIND = [
		'node_access' => ['node'],
		'entity_access' => ['media'],
	];

	/**
	 * Whether view access to a node follows only its status, owner and permissions.
	 */
	public function narrowable(): bool
	{
		if ($this->moduleHandler->hasImplementations('node_grants')) {
			return false;
		}
		foreach (self::FIELD_BLIND as $hook => $allowed) {
			$found = [];
			$this->moduleHandler->invokeAllWith($hook, function (callable $c, string $module) use (
				&$found,
			) {
				$found[] = $module;
			});
			if (array_diff($found, $allowed) !== []) {
				return false;
			}
		}
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function menuLinkCheckAccess(MenuLinkInterface $instance)
	{
		$result = parent::menuLinkCheckAccess($instance);
		$url = $instance->getUrlObject();
		if (!$url->isRouted() || $url->getRouteName() !== 'entity.node.canonical') {
			return $result;
		}
		$nid = (string) ($url->getRouteParameters()['node'] ?? '');
		if ($nid === '' || !($result instanceof CacheableDependencyInterface)) {
			return $result;
		}
		if (!in_array('node:' . $nid, $result->getCacheTags(), true) || !$this->narrowable()) {
			return $result;
		}
		return self::retag($result, $nid);
	}

	/**
	 * The same verdict and cacheability, with the node's tag swapped for the link tag.
	 */
	public static function retag(
		AccessResultInterface&CacheableDependencyInterface $result,
		string $nid,
	): AccessResultInterface {
		$reason = method_exists($result, 'getReason') ? (string) $result->getReason() : '';
		$copy = match (true) {
			$result->isAllowed() => AccessResult::allowed(),
			$result->isForbidden() => AccessResult::forbidden($reason === '' ? null : $reason),
			default => AccessResult::neutral($reason === '' ? null : $reason),
		};
		return $copy
			->addCacheContexts($result->getCacheContexts())
			->mergeCacheMaxAge($result->getCacheMaxAge())
			->addCacheTags(array_values(array_diff($result->getCacheTags(), ['node:' . $nid])))
			->addCacheTags([self::TAG . $nid]);
	}
}
