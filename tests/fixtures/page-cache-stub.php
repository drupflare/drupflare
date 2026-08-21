<?php

declare(strict_types=1);

namespace Drupal\page_cache\StackMiddleware;

/**
 * Stands in for core's page cache middleware, carrying the one property that is reset.
 */
class PageCache
{
	/**
	 * The memoized cache id, which a persistent kernel would otherwise serve stale from.
	 *
	 * @var string|null
	 */
	public $cid = 'cid:from-the-last-visitor';
}
