<?php

declare(strict_types=1);

namespace Drupal\drupflare\Routing;

use Drupal\Core\Routing\MatcherDumper;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Throwable;

/**
 * Skips a router dump that would rewrite the table with the bytes already in it.
 *
 * A rebuild is one `DELETE FROM router` plus every route re-inserted, unconditionally, whether or
 * not the collection changed. Measured on the shipped pack that is **419 routes against three
 * indexes -- 2,095 charged rows** -- and rows written is the meter that binds the regeneration
 * ceiling.
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
	 * A stable fingerprint of a route collection.
	 *
	 * Sorted by name, because a collection that differs only in iteration order produces the same
	 * table and must not read as a change.
	 *
	 * SEMANTIC FIELDS, NOT `serialize($route)`, and that distinction is the whole fix. A serialized
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
			if (!($route instanceof Route)) {
				$parts[$name] = (string) $name;
				continue;
			}
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
		} catch (Throwable) {
			return -1;
		}
	}
}
