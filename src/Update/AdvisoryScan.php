<?php

declare(strict_types=1);

namespace Drupal\drupflare\Update;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Turns the update module's project data into one flat record the host can read.
 *
 * The update module already computes everything needed: `update_project_data` carries a `status` per
 * project. What did not exist is anything that READS it, so a site could carry a known-insecure
 * module indefinitely with nothing saying so. `hook_requirements()` surfaces it on the status report,
 * which requires a human to look at one site at a time.
 *
 * WRITTEN AS JSON INTO STATE, deliberately flat. `update_project_data` is a nested serialized PHP
 * array, and a host-side reader for it would be a parser for however many shapes it happens to take;
 * a JSON string is a serialized SCALAR, which the host already decodes with one regex. The work of
 * understanding the structure happens here, in the process that owns it.
 *
 * The scan does not fetch. It reads what the last fetch left behind, so it costs no outbound request
 * and is safe to run on any cron firing.
 */
final class AdvisoryScan
{
	private const INSECURE = [
		// UPDATE_NOT_SECURE
		1 => 'not-secure',
		// UPDATE_REVOKED
		2 => 'revoked',
		// UPDATE_NOT_SUPPORTED
		3 => 'not-supported',
	];

	public const STATE_KEY = 'drupflare.advisories';

	public const SCHEMA = 1;

	public function __construct(
		private readonly StateInterface $state,
		private readonly KeyValueFactoryInterface $keyValue,
	) {}

	/**
	 * Reads the update module's computed data and records what it says.
	 *
	 * @param int $now
	 *   The timestamp to record the scan at.
	 *
	 * @return array
	 *   The record written, so a caller can act on it without a second read.
	 */
	public function scan(int $now): array
	{
		$projects = $this->keyValue->get('update')->get('update_project_data');
		$record = $this->summarise(is_array($projects) ? $projects : null, $now);
		$this->state->set(self::STATE_KEY, json_encode($record, JSON_UNESCAPED_SLASHES));

		return $record;
	}

	/**
	 * The record for one set of project rows.
	 *
	 * NULL project data and EMPTY project data are different facts and the record says which. Update
	 * has never run, its answer is still queued, or every project is current: the first two mean the
	 * scan knows nothing, and reporting them as "no advisories" is the false all-clear this exists to
	 * avoid. `checked` is what a caller gates on.
	 *
	 * @param array|null $projects
	 *   Rows as `update_calculate_project_data()` left them, or NULL when there are none.
	 * @param int $now
	 *   The timestamp to record.
	 *
	 * @return array
	 *   The flat record.
	 */
	public function summarise(?array $projects, int $now): array
	{
		if ($projects === null || $projects === []) {
			return [
				'schema' => self::SCHEMA,
				'at' => $now,
				'checked' => false,
				'reason' => 'the update module has computed no project data',
				'insecure' => [],
				'stale' => [],
			];
		}

		$insecure = [];
		$stale = [];
		foreach ($projects as $name => $data) {
			if (!is_array($data)) {
				continue;
			}
			$status = $data['status'] ?? null;
			if (!is_int($status)) {
				continue;
			}
			$entry = [
				'project' => (string) $name,
				'installed' => (string) ($data['existing_version'] ?? ''),
				'recommended' => (string) ($data['recommended'] ?? ''),
			];
			if (isset(self::INSECURE[$status])) {
				$entry['why'] = self::INSECURE[$status];
				$insecure[] = $entry;
			} elseif ($status === 4) {
				// UPDATE_NOT_CURRENT: behind, with no advisory attached. Reported separately because
				// conflating it with an advisory is how a security signal stops meaning anything
				$stale[] = $entry;
			}
		}

		return [
			'schema' => self::SCHEMA,
			'at' => $now,
			'checked' => true,
			'reason' => '',
			'insecure' => $insecure,
			'stale' => $stale,
		];
	}
}
