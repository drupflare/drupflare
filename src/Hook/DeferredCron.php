<?php

declare(strict_types=1);

namespace Drupal\drupflare\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\drupflare\Http\CachedFetchHandler;

/**
 * Reopens the update check when cron recorded it while its answer was still queued.
 *
 * A Worker cannot fetch synchronously, so the first request for a URL is queued and answered on the
 * next drain, seconds later. `UpdateProcessor::processFetchTask()` does not know that: it writes
 * `update.last_check` with the comment "whether this worked or not, we did just (try to) check for
 * updates", which is correct on a host where a failure means the network is down and wrong here,
 * where it means the answer is on its way.
 *
 * MEASURED, and it is why the status report said "Failed to get available update data" forever
 * rather than for one cron: the first run queued four URLs and the drain fetched every one into the
 * cache, 540 KB of core release history among them -- and no later cron ever asked again, so the
 * answers sat unread until they expired.
 *
 * THREE RECORDS, not one, and stopping at the first is why an earlier attempt looked correct and
 * changed nothing. `processFetchTask()` writes all of them on the failing path:
 *
 *   - `update.last_check` in state, which gates `update_cron` by `check.interval_days`;
 *   - a per-project row in `update_available_releases`, stamped with the same interval as its
 *     expiry, which is what `fetchData()` consults to decide a project needs no refresh;
 *   - `update_project_data`, the computed summary built from those rows.
 *
 * SCOPED TO THE UPDATE MODULE'S OWN URL, and this is the second thing an earlier attempt got wrong.
 * Reopening on ANY deferral meant a deferred `announcements.json` cleared release data that the same
 * cron had just fetched successfully, so it was refetched and discarded on every run -- which reads
 * exactly like a fetch that never works. The base URL comes from `update.settings` rather than a
 * literal, because a site may point at its own release server.
 *
 * `Order::Last` is load-bearing. This has to run AFTER `update_cron` in the same firing, or it
 * clears records that the very run it is correcting then writes again.
 */
final class DeferredCron
{
	// what `UpdateFetcher` falls back to, and what a stock site uses
	private const DEFAULT_FETCH_URL = 'https://updates.drupal.org/release-history';

	public function __construct(
		private readonly StateInterface $state,
		private readonly ConfigFactoryInterface $configFactory,
		private readonly KeyValueFactoryInterface $keyValue,
		private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
	) {}

	/**
	 * Implements hook_cron().
	 */
	#[Hook('cron', order: Order::Last)]
	public function cron(): void
	{
		$deferred = CachedFetchHandler::deferredUrls();
		CachedFetchHandler::clearDeferred();
		if ($deferred === []) {
			return;
		}

		$base =
			(string) ($this->configFactory->get('update.settings')->get('fetch.url') ?:
			self::DEFAULT_FETCH_URL);
		foreach ($deferred as $url) {
			if (!is_string($url) || !str_starts_with($url, $base)) {
				continue;
			}
			$this->state->delete('update.last_check');
			$this->keyValueExpirable->get('update_available_releases')->deleteAll();
			$this->keyValue->get('update')->delete('update_project_data');
			return;
		}
	}
}
