<?php

declare(strict_types=1);

namespace Drupal\drupflare\Ops;

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\State\StateInterface;
use Throwable;

/**
 * Drupal's ConfigImporter, run a few operations per call so an import outlives no invocation.
 *
 * `ConfigImporter::import()` runs every extension and configuration operation in one request. Here
 * a call is a beat: a fresh importer is built over the stored payload, runs up to a budget of
 * operations through `doSyncStep()`, releases the import lock and reports what is left. Nothing
 * lives on the importer between beats. An operation that has run is in the active storage, so the
 * next beat's changelist no longer holds it, and a dropped interpreter loses nothing.
 *
 * The first call carries the whole source tree as `payload` and replaces any run in progress; the
 * calls after it carry nothing and continue until the answer says `done`. Like `drush config:import`
 * a payload is authoritative for the default collection, so objects it omits are deleted. A
 * collection the payload does not mention is left as it is.
 *
 * The run's progress is one state key and its payload another, dropped when the run ends.
 */
final class ConfigImportStepper
{
	public const STATE = 'drupflare.cim';

	public const PAYLOAD = 'drupflare.cim.payload';

	/**
	 * Operations one beat may run; an extension install or uninstall costs all of it.
	 */
	public const BUDGET = 10;

	/**
	 * Runs one beat of a config import.
	 *
	 * @param StateInterface $state
	 *   Holds the run's progress and payload.
	 * @param callable(array<string, array<string, mixed>>, array<string, array<string, array<string, mixed>>>): array{0: ConfigImporter, 1: StorageComparer, 2: callable(): void} $build
	 *   Makes the importer, its comparer and a callable that releases the import lock.
	 * @param array{payload?: mixed, collections?: mixed, budget?: mixed} $options
	 *   The payload starts a run, collections names replaced collections and budget lowers the
	 *   beat size.
	 *
	 * @return array<string, mixed>
	 *   Keys ok, done, phase (running, done or failed), processed this beat, remaining, total and
	 *   errors.
	 */
	public static function step(StateInterface $state, callable $build, array $options): array
	{
		$payload = $options['payload'] ?? null;
		$collections = is_array($options['collections'] ?? null) ? $options['collections'] : [];
		if ($payload !== null) {
			if (!is_array($payload) || $payload === []) {
				return ['ok' => false, 'error' => 'cim needs a payload of config objects'];
			}
			[, $comparer] = $build($payload, $collections);
			$comparer->createChangelist();
			$run = [
				'phase' => 'running',
				'total' => self::count($comparer),
				'processed' => 0,
				'errors' => [],
			];
			$state->set(self::PAYLOAD, ['default' => $payload, 'collections' => $collections]);
		} else {
			$run = $state->get(self::STATE);
			if (!is_array($run)) {
				return [
					'ok' => false,
					'error' => 'no config import is in progress; send a payload',
				];
			}
			if ($run['phase'] !== 'running') {
				return self::report($run, 0, true);
			}
			$stored = $state->get(self::PAYLOAD);
			if (!is_array($stored) || !is_array($stored['default'] ?? null)) {
				$run['phase'] = 'failed';
				$run['errors'][] = 'the stored payload is gone';
				$state->set(self::STATE, $run);
				return self::report($run, 0, true);
			}
			$payload = $stored['default'];
			$collections = (array) ($stored['collections'] ?? []);
		}

		$budget = max(1, min((int) ($options['budget'] ?? self::BUDGET), self::BUDGET));
		$beat = self::beat($build, $payload, $collections, $budget);
		$run['processed'] += $beat['units'];
		$run['errors'] = array_values(array_unique(array_merge($run['errors'], $beat['errors'])));
		$run['phase'] = $beat['failed'] ? 'failed' : ($beat['done'] ? 'done' : 'running');
		$run['remaining'] = $beat['remaining'];
		$state->set(self::STATE, $run);
		if ($run['phase'] !== 'running') {
			$state->delete(self::PAYLOAD);
		}
		return self::report($run, $beat['units'], $run['phase'] !== 'running');
	}

	/**
	 * Runs one beat over a fresh importer.
	 *
	 * @param callable $build
	 *   The importer factory given to step().
	 * @param array<string, array<string, mixed>> $payload
	 *   Default-collection objects by name.
	 * @param array<string, array<string, array<string, mixed>>> $collections
	 *   Objects by collection, then name.
	 * @param int $budget
	 *   Operations this beat may run.
	 *
	 * @return array{units: int, done: bool, failed: bool, remaining: int, errors: string[]}
	 *   What the beat did and what is left.
	 */
	private static function beat(
		callable $build,
		array $payload,
		array $collections,
		int $budget,
	): array {
		[$importer, $comparer, $release] = $build($payload, $collections);
		$comparer->createChangelist();
		if (!$comparer->hasChanges()) {
			return [
				'units' => 0,
				'done' => true,
				'failed' => false,
				'remaining' => 0,
				'errors' => [],
			];
		}
		$units = 0;
		$finished = false;
		$failed = false;
		$reason = null;
		try {
			foreach ($importer->initialize() as $step) {
				$context = [];
				do {
					if ($units >= $budget && $step !== 'finish') {
						break 2;
					}
					$progress = self::advance($importer, $step, $context);
					$units += $step === 'processExtensions' ? $budget : 1;
				} while ($progress < 1);
				$finished = $step === 'finish';
			}
		} catch (ConfigImporterException $e) {
			$failed = true;
			$reason = $e->getMessage();
		} catch (Throwable $e) {
			$failed = true;
			$reason = get_class($e) . ': ' . $e->getMessage();
		} finally {
			$release();
		}
		$errors = array_map(strval(...), $importer->getErrors());
		if ($errors === [] && $reason !== null) {
			$errors[] = $reason;
		}
		$remaining = 0;
		if (!$finished && !$failed) {
			$comparer->reset();
			$comparer->createChangelist();
			$remaining = self::count($comparer);
		}
		return [
			'units' => $units,
			'done' => $finished && !$failed && $errors === [],
			'failed' => $failed || ($finished && $errors !== []),
			'remaining' => $remaining,
			'errors' => $errors,
		];
	}

	/**
	 * The source storage for a payload: what it names, plus the target's untouched collections.
	 *
	 * @param array<string, array<string, mixed>> $payload
	 *   Default-collection objects by name.
	 * @param array<string, array<string, array<string, mixed>>> $collections
	 *   Objects by collection, then name.
	 * @param StorageInterface $target
	 *   The active storage whose other collections are mirrored.
	 *
	 * @return MemoryStorage
	 *   The storage to compare against the target.
	 */
	public static function source(
		array $payload,
		array $collections,
		StorageInterface $target,
	): MemoryStorage {
		$source = new MemoryStorage();
		foreach ($payload as $name => $data) {
			if (is_string($name) && is_array($data)) {
				$source->write($name, $data);
			}
		}
		foreach ($collections as $collection => $objects) {
			$store = $source->createCollection((string) $collection);
			foreach ((array) $objects as $name => $data) {
				if (is_string($name) && is_array($data)) {
					$store->write($name, $data);
				}
			}
		}
		foreach ($target->getAllCollectionNames() as $collection) {
			if (
				$collection === StorageInterface::DEFAULT_COLLECTION ||
				isset($collections[$collection])
			) {
				continue;
			}
			$from = $target->createCollection($collection);
			$store = $source->createCollection($collection);
			foreach ($from->listAll() as $name) {
				$data = $from->read($name);
				if (is_array($data)) {
					$store->write($name, $data);
				}
			}
		}
		return $source;
	}

	/**
	 * Runs one sync step and reads how far the step says it is.
	 *
	 * `doSyncStep()` reports through a by-reference batch context; a step that has nothing left to
	 * do sets `finished` to 1.
	 *
	 * @param ConfigImporter $importer
	 *   The importer for this beat.
	 * @param string|callable $step
	 *   A step from initialize().
	 * @param array $context
	 *   Kept by the caller across calls of one step, because some steps park state in it.
	 *
	 * @param-out array $context
	 *
	 * @return float
	 *   How far the step is, 1 when it is finished.
	 */
	private static function advance(ConfigImporter $importer, mixed $step, array &$context): float
	{
		$importer->doSyncStep($step, $context);
		return (float) ($context['finished'] ?? 0);
	}

	/**
	 * Operations still in the changelist, across every collection.
	 *
	 * @param StorageComparer $comparer
	 *   The comparer with its changelist built.
	 *
	 * @return int
	 *   The number of pending operations.
	 */
	private static function count(StorageComparer $comparer): int
	{
		$count = 0;
		foreach ($comparer->getAllCollectionNames() as $collection) {
			foreach ($comparer->getChangelist(null, $collection) as $names) {
				$count += count($names);
			}
		}
		return $count;
	}

	/**
	 * Shapes the run into the answer a call returns.
	 *
	 * @param array<string, mixed> $run
	 *   The stored progress.
	 * @param int $units
	 *   Units spent in this beat.
	 * @param bool $ended
	 *   Whether the run is over.
	 *
	 * @return array<string, mixed>
	 *   The report.
	 */
	private static function report(array $run, int $units, bool $ended): array
	{
		return [
			'ok' => $run['phase'] !== 'failed',
			'done' => $ended,
			'phase' => $run['phase'],
			'processed' => $units,
			'total' => $run['total'],
			'remaining' => $run['remaining'] ?? 0,
			'errors' => $run['errors'],
		];
	}
}
