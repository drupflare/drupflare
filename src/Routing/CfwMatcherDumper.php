<?php

declare(strict_types=1);

namespace Drupal\drupflare\Routing;

use Drupal\Core\Routing\MatcherDumper;
use Symfony\Component\Routing\RouteCollection;
use Throwable;

/**
 * Skips a router dump that would rewrite the table with the bytes already in it.
 *
 * A rebuild is one `DELETE FROM router` plus every route re-inserted, unconditionally, whether or
 * not the collection changed. Measured on the shipped pack that is **419 routes against three
 * indexes -- 2,095 charged rows** -- and rows written is the meter that binds the regeneration
 * ceiling. {@see ensurePartialAliasIndex()} takes the third index off the 402 routes that store a
 * NULL alias, so a rebuild is **1,693**: the 17 routes that DO carry an alias still pay for it.
 *
 * Those rebuilds repeat. `ModuleInstaller::doInstall()` calls `updateKernel()` once per module,
 * which rebuilds the container, which constructs a fresh `RouteProviderLazyBuilder` whose
 * `$rebuilt` flag starts FALSE again -- so the first route access during each module's install
 * triggers another full rebuild. Core acknowledges the container-rebuild-in-a-loop problem
 * in its own comment at `ModuleInstaller.php:328` and only fixes the provider swap.
 */
class CfwMatcherDumper extends MatcherDumper
{
	/**
	 * State key holding the fingerprint of the last dump.
	 *
	 * In state rather than a cache bin: a cache flush is exactly when a rebuild is most likely, and
	 * losing the fingerprint there would only cost a rebuild that was already going to happen.
	 */
	public const FINGERPRINT_KEY = 'drupflare.router_fingerprint';

	/**
	 * Dumps attempted and dumps skipped in THIS php run.
	 */
	public static int $dumps = 0;

	/**
	 * Dumps skipped because the table already held the bytes that would be written.
	 */
	public static int $skips = 0;

	/**
	 * {@inheritdoc}
	 */
	public function dump(array $options = []): string
	{
		$routes = $this->routes;
		if ($routes === null) {
			return parent::dump($options);
		}

		self::$dumps++;
		$fingerprint = self::fingerprint($routes);
		$stored = $this->state->get(self::FINGERPRINT_KEY);
		if (
			is_array($stored) &&
			($stored['hash'] ?? null) === $fingerprint &&
			$this->tableMatches($stored['rows'] ?? -1)
		) {
			// identical collection, table intact: the 2,095 rows this would write are already there
			self::$skips++;

			// @phpstan-ignore assign.propertyType
			$this->routes = null;
			return '';
		}

		$dumped = parent::dump($options);
		$this->state->set(self::FINGERPRINT_KEY, [
			'hash' => $fingerprint,
			'rows' => $this->countRows(),
		]);
		return $dumped;
	}

	/**
	 * Creates the table, then makes its alias index partial.
	 *
	 * {@inheritdoc}
	 */
	protected function ensureTableExists(): bool
	{
		$created = parent::ensureTableExists();
		$this->ensurePartialAliasIndex();
		return $created;
	}

	/**
	 * The router schema without core's full alias index.
	 *
	 * Drupal's schema API cannot express a partial index, so the index is omitted here and created
	 * as SQL by {@see ensurePartialAliasIndex()}. Leaving core's entry in would create the full
	 * index first and pay for it once before the swap.
	 *
	 * @return array
	 *   The schema API definition, minus the `alias` index.
	 */
	protected function schemaDefinition(): array
	{
		$schema = parent::schemaDefinition();
		unset($schema['indexes']['alias']);
		return $schema;
	}

	/**
	 * Replaces the full `alias` index with one that stores only the rows that have an alias.
	 *
	 * Every index on a table is a charged row per insert, so the full index bills all 419 routes
	 * while **402 of them are NULL** -- 96%. A rebuild is one DELETE plus one INSERT per route, and
	 * the insert side falls from 4 charged rows per route to 3.
	 *
	 * Both queries core aims at the column keep working, and one changes plan. `getRouteAliases()`
	 * filters `condition('alias', $name)`, which implies the predicate, so sqlite still answers it
	 * with `SEARCH router USING INDEX router_alias`. `getAllRoutes()` filters `isNull('alias')`,
	 * which the full index DID serve -- sqlite treats `IS NULL` as an indexable equality -- and now
	 * scans instead. That query returns 402 of 419 rows and reads the `route` blob for each, so the
	 * index was seeking almost the whole table; and it enumerates the collection rather than
	 * matching a request, so no page render reaches it. Route matching goes through
	 * `pattern_outline` and is untouched.
	 *
	 * Idempotent, and never fatal: an index that cannot be swapped is a cost rather than a
	 * correctness problem, so the site keeps serving on the full index.
	 */
	protected function ensurePartialAliasIndex(): void
	{
		$index = $this->tableName . '_alias';
		try {
			$existing = $this->connection
				->query('SELECT sql FROM sqlite_master WHERE type = :type AND name = :name', [
					':type' => 'index',
					':name' => $index,
				])
				->fetchField();

			// a partial index carries its own predicate, so finding one means the swap already ran
			if (is_string($existing) && stripos($existing, ' WHERE ') !== false) {
				return;
			}
			if (is_string($existing) && $existing !== '') {
				$this->connection->query('DROP INDEX ' . $index);
			}
			$this->connection->query(
				'CREATE INDEX IF NOT EXISTS ' .
					$index .
					' ON {' .
					$this->tableName .
					'} (alias) WHERE alias IS NOT NULL',
			);
		} catch (Throwable $e) {
			// survivable, but not free and no longer silent: the full index bills all 419 routes
			// where 402 store a NULL alias, so a permanently failing swap is ~20% of every rebuild
			// on the meter that BINDS regeneration, and nothing could see it
			self::$indexSwapError = $e->getMessage();
		}
	}

	/**
	 * Why the partial-alias swap last failed, or NULL when it has not.
	 *
	 * Read by `Hook\Requirements`, so a permanent overcharge appears in the status report instead
	 * of being inferred from a row count nobody is watching.
	 */
	public static ?string $indexSwapError = null;

	/**
	 * A stable fingerprint of a route collection.
	 *
	 * Sorted by name, because a collection that differs only in iteration order produces the same
	 * table and must not read as a change.
	 *
	 * Semantic fields, not `serialize($route)`. A serialized
	 * Route carries its COMPILED form, and compilation happens lazily -- so the same collection
	 * hashes differently depending on whether anything asked for a compiled route before the dump.
	 * Measured: with the serialized form, enabling token still wrote 17,200 router rows because no
	 * two passes ever agreed. These accessors return only what the route MEANS, which is also
	 * exactly what gets written to the table.
	 *
	 * @param RouteCollection $routes
	 *   The collection about to be dumped.
	 *
	 * @return string
	 *   A hex digest.
	 */
	protected static function fingerprint(RouteCollection $routes): string
	{
		$parts = [];
		foreach ($routes->all() as $name => $route) {
			$defaults = $route->getDefaults();
			$requirements = $route->getRequirements();
			$options = $route->getOptions();
			ksort($defaults);
			ksort($requirements);
			ksort($options);
			$methods = $route->getMethods();
			$schemes = $route->getSchemes();
			sort($methods);
			sort($schemes);
			$parts[$name] = [
				$route->getPath(),
				$route->getHost(),
				$defaults,
				$requirements,
				$options,
				$methods,
				$schemes,
				$route->getCondition(),
			];
		}
		ksort($parts);

		$aliases = [];
		foreach ($routes->getAliases() as $name => $alias) {
			$aliases[$name] = $alias->getId();
		}
		ksort($aliases);

		return hash('sha256', serialize([$parts, $aliases]));
	}

	/**
	 * Whether the router table still holds what the recorded dump wrote.
	 *
	 * @param int $expected
	 *   The row count stored alongside the fingerprint.
	 *
	 * @return bool
	 *   TRUE when the table is intact, FALSE when it is missing, empty or a different size.
	 */
	protected function tableMatches($expected): bool
	{
		if (!is_int($expected) || $expected <= 0) {
			return false;
		}
		return $this->countRows() === $expected;
	}

	/**
	 * Counts the rows in the router table, or -1 when it cannot be read.
	 *
	 * @return int
	 *   Rows in the router table, or -1 when the table cannot be read at all.
	 */
	protected function countRows(): int
	{
		try {
			return (int) $this->connection
				->select($this->tableName)
				->countQuery()
				->execute()
				->fetchField();
		} catch (Throwable $e) {
			// -1 is the caller's "unknown", and it used to be indistinguishable from a table that
			// really holds -1 rows -- which is to say, from nothing at all
			self::$rowCountError = $e->getMessage();
			return -1;
		}
	}

	/**
	 * Why the last row count failed, or NULL when it has not.
	 *
	 * `countRows()` returns -1 for "could not read", which is reported as a number and reads as a
	 * measurement. This is what separates the two.
	 */
	public static ?string $rowCountError = null;
}
