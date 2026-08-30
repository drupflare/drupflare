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
 * FOUR RECORDS, and the one that actually latches is `fetch_failures`. `processFetchTask()` skips
 * `fetchProjectData()` entirely once that counter reaches `update.settings:fetch.max_attempts`,
 * which is 2 - and one cron round over five projects on a cold cache drives it to 5 before any
 * answer has had time to arrive. From then on nothing even asks: the counter is rewritten with a
 * fresh five-minute expiry on every round, so it never ages out while cron keeps running, and 540 KB
 * of core release history sits fresh in the fetch cache being ignored. Measured at 13 and climbing.
 *
 *   - `update.last_check` in state, which gates `update_cron` by `check.interval_days`;
 *   - `fetch_failures` in the `update` collection, which gates the fetch itself;
 *   - the per-project rows in `update_available_releases`;
 *   - `update_project_data`, the computed summary built from those rows.
 *
 * ONLY THE UNANSWERED ROWS ARE DROPPED. Deleting them all is what an earlier version did, and every
 * project fetch shares one base URL - so a round where project 1 hit the cache and project 2
 * deferred threw away project 1's real answer along with project 2's placeholder, forever. A row
 * carrying `project_status: not-fetched` is the placeholder the failing path writes; anything else
 * is an answer and has to survive.
 *
 * SCOPED TO THE UPDATE MODULE'S OWN URL. Reopening on ANY deferral meant a deferred
 * `announcements.json` cleared release data the same cron had just fetched. The base URL comes from
 * `update.settings` rather than a literal, because a site may point at its own release server.
 *
 * `Order::Last` is load-bearing WHERE THE HOST RUNS drupal_cron(): this has to come after
 * `update_cron`, or it clears records that the very run it is correcting then writes again.
 *
 * ON THE EDGE THERE IS NO SUCH FIRING, and keying off the deferral alone meant this never ran. The
 * host slices cron into one hook per interpreter unit, each with its own kernel boot, so
 * `CachedFetchHandler`'s in-process static is empty by the time this is invoked and `update_cron`
 * may be several firings behind. The durable half is what makes it work: a `not-fetched` row IS the
 * record that a fetch did not land, it survives any number of boots, and reopening on it is
 * self-healing regardless of which unit ran when. The static check stays because it is correct when
 * it does fire, and it reopens a round earlier.
 */
final class DeferredCron
{
	// what `UpdateFetcher` falls back to, and what a stock site uses
	private const DEFAULT_FETCH_URL = 'https://updates.drupal.org/release-history';

	// what `processFetchTask()` stores when it could not fetch
	private const NOT_FETCHED = 'not-fetched';

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

		if ($this->deferredOurs($deferred) || $this->hasUnanswered()) {
			$this->reopen();
		}
	}

	/**
	 * Whether one of the deferred URLs belongs to the update module's release server.
	 *
	 * @param array $deferred
	 *   URLs queued rather than answered since the flag was last cleared.
	 *
	 * @return bool
	 *   TRUE when at least one is the update module's own.
	 */
	private function deferredOurs(array $deferred): bool
	{
		if ($deferred === []) {
			return false;
		}
		$base =
			(string) ($this->configFactory->get('update.settings')->get('fetch.url') ?:
			self::DEFAULT_FETCH_URL);
		foreach ($deferred as $url) {
			if (is_string($url) && str_starts_with($url, $base)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a placeholder from a previous round is still standing in for release data.
	 *
	 * @return bool
	 *   TRUE when any project carries the not-fetched marker.
	 */
	private function hasUnanswered(): bool
	{
		foreach ($this->keyValueExpirable->get('update_available_releases')->getAll() as $data) {
			if (is_array($data) && ($data['project_status'] ?? null) === self::NOT_FETCHED) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Puts the update module back where it was before the deferral was mistaken for a failure.
	 *
	 * @return void
	 */
	private function reopen(): void
	{
		$this->state->delete('update.last_check');
		// the gate that makes this worth doing at all; without it the next round is skipped too
		$this->keyValueExpirable->get('update')->delete('fetch_failures');

		$releases = $this->keyValueExpirable->get('update_available_releases');
		$unanswered = [];
		foreach ($releases->getAll() as $project => $data) {
			if (is_array($data) && ($data['project_status'] ?? null) === self::NOT_FETCHED) {
				$unanswered[] = $project;
			}
		}
		if ($unanswered !== []) {
			$releases->deleteMultiple($unanswered);
		}

		// derived from the rows above, so it is stale whenever any of them moved
		$this->keyValue->get('update')->delete('update_project_data');
	}
}
