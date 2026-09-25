<?php

declare(strict_types=1);

namespace Drupal\drupflare\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\drupflare\Host;
use Stringable;

/**
 * The Drupflare status page: what the Worker already reports, rendered.
 *
 * There was no Drupflare surface in the admin UI at all. /admin/config had no section, /admin/help
 * had no topic, and the replica pool, the meters, the fill queue and the outbound queues were
 * readable only from a host route an administrator has no reason to know about. Every number here
 * already existed; the gap was display and not storage, which is why this reads one capability and
 * invents no settings.
 *
 * The two fields /serve-stats has and this does not are the stored cron timestamp and the pending
 * alarm. Both come from ctx.storage, which is asynchronous, and PHP here cannot await.
 */
final class StatusController extends ControllerBase
{
	/**
	 * The page.
	 *
	 * @return array
	 *   A render array.
	 */
	public function page(): array
	{
		$stats = self::stats();
		if ($stats === null) {
			return [
				'#type' => 'container',
				'note' => [
					'#theme' => 'status_messages',
					'#message_list' => [
						'warning' => [
							$this->t(
								'This site is not running inside a Drupflare Worker, so there is nothing to report.',
							),
						],
					],
				],
			];
		}

		$lane = ($stats['replica']['role'] ?? 'primary') !== 'primary';

		return [
			'meters' => self::table((string) $this->t('Daily Meters'), [
				// AGAINST THE ALLOWANCE, not as a bare count. A site that spends its quota goes
				// read-only until midnight UTC, and "104,451" on its own says nothing about that
				(string) $this->t('Rows written today') => self::against(
					$stats,
					'rows-written',
					$stats['rowsToday'] ?? null,
				),
				(string) $this->t('Object requests today') => self::against(
					$stats,
					'do-requests',
					$stats['doRequestsToday'] ?? null,
				),
				(string) $this->t('Worker requests served') => self::plain(
					$stats['serveRequests'] ?? null,
				),
				(string) $this->t('Generation') => self::plain($stats['generation'] ?? null),
				(string) $this->t('Invalidations') => self::plain($stats['bumps'] ?? null),
				// the rollback unit. The platform fills CF_VERSION_METADATA in for free and the
				// only reader was the fleet inventory write, so an operator could not find out
				// which Worker was serving their site from anywhere in the product
				(string) $this->t('Worker version') => self::plain(
					$stats['workerVersion']['id'] ?? null,
				),
			]),
			'replica' => self::table((string) $this->t('Read Replica Pool'), [
				(string) $this->t('Role') => self::plain($stats['replica']['role'] ?? null),
				// a primary has no lane or stage; `CREATED` there read as a lane never admitted
				(string) $this->t('Lane') => self::plain(
					$lane ? $stats['replica']['lane'] ?? null : null,
				),
				(string) $this->t('Stage') => self::plain(
					$lane ? $stats['replica']['stage'] ?? null : null,
				),
				(string) $this->t('Guarded capabilities') => self::plain(
					$stats['replica']['guarded'] ?? null,
				),
				(string) $this->t('Refusals') => self::plain($stats['replica']['refusals'] ?? null),
				(string) $this->t('Last refusal') => self::plain(
					$stats['replica']['lastRefusal'] ?? null,
				),
				(string) $this->t('Catch-up lag') => self::plain(
					$stats['replica']['lastCatchUp']['behind'] ?? null,
				),
			]),
			'pages' => self::table((string) $this->t('Page Tiers'), [
				(string) $this->t('Stored pages') => self::plain(count($stats['cached'] ?? [])),
				(string) $this->t('Fill queue depth') => self::plain(count($stats['queue'] ?? [])),
				(string) $this->t('Shell candidates') => self::plain(
					is_array($stats['shellCandidates'] ?? null)
						? $this->t('@safe safe, @unsafe unsafe', [
							'@safe' => (int) ($stats['shellCandidates']['safe'] ?? 0),
							'@unsafe' => (int) ($stats['shellCandidates']['unsafe'] ?? 0),
						])
						: $stats['shellCandidates'] ?? null,
				),
				(string) $this->t('Interpreter booted') => self::yesNo($stats['phpBooted'] ?? null),
				(string) $this->t('Interpreter recycles') => self::plain(
					$stats['recycles'] ?? null,
				),
			]),
			'outbound' => self::table((string) $this->t('Outbound and Mail'), [
				(string) $this->t('Deferred HTTP queue') => self::plain(
					$stats['httpQueue'] ?? null,
				),
				(string) $this->t('Mail queue') => self::plain($stats['mailQueue'] ?? null),
				(string) $this->t('Mail transport') => self::plain(
					$stats['mailTransport'] ?? $this->t('none configured'),
				),
				(string) $this->t('Alarm firings') => self::plain($stats['alarmFirings'] ?? null),
			]),
			// the fill queue is where a poisoned path shows up, and a path stuck on three strikes
			// was invisible to an administrator until now
			'queue' => self::queueTable($stats['queue'] ?? []),
			'#cache' => ['max-age' => 0],
		];
	}

	/**
	 * Reads the host snapshot.
	 *
	 * @return array|null
	 *   The stats, or NULL when this is not running under the Worker.
	 */
	public static function stats(): ?array
	{
		$invoke = Host::fn('cfwServeStats');
		if ($invoke === null) {
			return null;
		}
		$decoded = json_decode((string) $invoke(), true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * One label/value table.
	 *
	 * @param string $title
	 *   The section heading.
	 * @param array $rows
	 *   Label to render array.
	 *
	 * @return array
	 *   A render array.
	 */
	private static function table(string $title, array $rows): array
	{
		$built = [];
		foreach ($rows as $label => $value) {
			// a bare array cell is read as attributes, which rendered every value blank
			$built[] = [$label, ['data' => $value]];
		}
		return [
			'#type' => 'details',
			'#title' => $title,
			'#open' => true,
			'table' => [
				'#type' => 'table',
				'#header' => [t('Item'), t('Value')],
				'#rows' => $built,
			],
		];
	}

	/**
	 * The fill queue, with the error that stopped each path.
	 *
	 * @param array $queue
	 *   Rows from the host snapshot.
	 *
	 * @return array
	 *   A render array.
	 */
	private static function queueTable(array $queue): array
	{
		$rows = [];
		foreach ($queue as $row) {
			if (!is_array($row)) {
				continue;
			}
			$rows[] = [
				(string) ($row['path'] ?? ''),
				(int) ($row['attempts'] ?? 0),
				(string) ($row['last_error'] ?? ''),
			];
		}
		return [
			'#type' => 'details',
			'#title' => t('Fill Queue'),
			'#open' => $rows !== [],
			'table' => [
				'#type' => 'table',
				'#header' => [t('Path'), t('Attempts'), t('Last Error')],
				'#rows' => $rows,
				'#empty' => t('Nothing is queued.'),
			],
		];
	}

	/**
	 * One daily meter against its allowance, from the host's own reading of it.
	 *
	 * The allowance and the percentage come out of `spend.lines`, which already carries both per
	 * meter for the resolved plan. Restating 100,000 here would be a second copy of a number the
	 * host resolves per plan, and the two would disagree the first time a plan changed.
	 *
	 * @param array $stats
	 *   The host snapshot.
	 * @param string $meter
	 *   The meter id, as `THRESHOLDS` names it.
	 * @param mixed $used
	 *   The count to render.
	 *
	 * @return array
	 *   A render array.
	 */
	private static function against(array $stats, string $meter, mixed $used): array
	{
		if (!is_numeric($used)) {
			return self::plain($used);
		}
		foreach ($stats['spend']['lines'] ?? [] as $line) {
			if (
				!is_array($line) ||
				($line['meter'] ?? '') !== $meter ||
				!is_numeric($line['allowance'] ?? null) ||
				!is_numeric($line['percentOfAllowance'] ?? null)
			) {
				continue;
			}
			return [
				'#plain_text' => (string) t('@used of @allowance (@percent%)', [
					'@used' => number_format((int) $used),
					'@allowance' => number_format((int) $line['allowance']),
					'@percent' => number_format((float) $line['percentOfAllowance'], 1),
				]),
			];
		}
		return self::plain($used);
	}

	/**
	 * A scalar as markup, with a dash for absent rather than an empty cell.
	 *
	 * @param mixed $value
	 *   Anything scalar.
	 *
	 * @return array
	 *   A render array.
	 */
	private static function plain(mixed $value): array
	{
		if ($value === null || $value === '') {
			return ['#markup' => '&mdash;'];
		}
		// a translated fallback is Stringable, and json_encode wrapped it in quotes
		if (is_scalar($value) || $value instanceof Stringable) {
			return ['#plain_text' => (string) $value];
		}
		return ['#plain_text' => json_encode($value)];
	}

	/**
	 * A boolean as words.
	 *
	 * @param mixed $value
	 *   Anything truthy.
	 *
	 * @return array
	 *   A render array.
	 */
	private static function yesNo(mixed $value): array
	{
		return ['#plain_text' => $value ? (string) t('Yes') : (string) t('No')];
	}
}
