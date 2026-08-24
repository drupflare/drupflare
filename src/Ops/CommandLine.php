<?php

namespace Drupal\drupflare\Ops;

/**
 * Parses one command line into something this runtime can execute.
 *
 * Four vocabularies, because an operator arrives knowing one of them and none of them is this
 * platform's: `drush`, `composer`, `npm` and `bun`. The terminal accepts all four and translates.
 *
 * ## What is a translation and what is a refusal
 *
 * Most of drush translates directly: {@see OpsRegistry} carries the operations, and this maps every
 * alias and long form onto them. `drush cr`, `drush cache:rebuild` and `drush cache-rebuild` are one
 * operation with three spellings, and an operator should not have to learn which one this runtime
 * prefers.
 *
 * `composer require` is a real INSTALL rather than a refusal. The packed tree is the vendor
 * directory, so there is no `composer` binary to run, but the thing an operator wants -- new PHP in
 * the tree -- is reachable: resolve the package to a dist URL, fetch it, and write it in. That work
 * is the host's because PHP here cannot block on a socket, so this half resolves the INTENT and
 * hands it over.
 *
 * `npm` and `bun` parse to the same intent with a different registry. Whether a JavaScript package
 * can do anything once installed is a separate question the host answers; a module contributing JS
 * is contributing entries to the host bridge table, and that is bundle-time work.
 *
 * What stays refused is arbitrary execution -- `php`, `eval`, raw `sql`, `ssh`. An owner token gates
 * who reaches the terminal; it does not make a `php` prompt safe, because the token is also what a
 * stolen session carries.
 */
final class CommandLine
{
	/**
	 * Refused with a REASON, keyed by the first word.
	 *
	 * These are refused on what they DO rather than on being unavailable, so the message says why
	 * rather than suggesting a spelling.
	 */
	public const REFUSED = [
		'php' =>
			'arbitrary PHP execution is a full remote-code surface and is not part of this terminal',
		'eval' =>
			'arbitrary PHP execution is a full remote-code surface and is not part of this terminal',
		'sql' =>
			'arbitrary SQL is behind the diagnostic gate rather than here; a typed query can drop the site',
		'ssh' => 'there is no shell on this runtime and no process to connect to',
		'rsync' => 'there is no filesystem to sync; files live in the object database',
		'exec' => 'there is no process table, so nothing can be executed',
	];

	private const PREFIXES = ['drush', './vendor/bin/drush', 'vendor/bin/drush'];

	/**
	 * Every drush spelling this runtime answers, mapped onto a registry operation.
	 *
	 * Drush names a command `namespace:verb` and gives it a short alias and a legacy hyphenated
	 * form, so one operation has three spellings in the wild. All three are here because an operator
	 * types the one they already know.
	 */
	public const DRUSH_ALIASES = [
		// cache
		'cr' => 'cr',
		'cache:rebuild' => 'cr',
		'cache-rebuild' => 'cr',
		'rebuild' => 'cr',
		'cc' => 'cr',
		'cache:clear' => 'cr',
		// database updates
		'updb' => 'updb',
		'updatedb' => 'updb',
		'updatedb:status' => 'updb',
		// configuration
		'cex' => 'cex',
		'config:export' => 'cex',
		'config-export' => 'cex',
		'cim' => 'cim',
		'config:import' => 'cim',
		'config-import' => 'cim',
		// modules and themes
		'en' => 'en',
		'pm:install' => 'en',
		'pm-enable' => 'en',
		'pm:enable' => 'en',
		'theme:enable' => 'en',
		'pmu' => 'pmu',
		'pm:uninstall' => 'pmu',
		'pm-uninstall' => 'pmu',
		'theme:uninstall' => 'pmu',
		// status
		'status' => 'status',
		'core:status' => 'status',
		'core-status' => 'status',
		'st' => 'status',
		// database dump
		'sql-dump' => 'sql-dump',
		'sql:dump' => 'sql-dump',
	];

	public const PACKAGE_MANAGERS = [
		'composer' => 'composer',
		'npm' => 'npm',
		'bun' => 'npm',
		'yarn' => 'npm',
		'pnpm' => 'npm',
	];

	private const PACKAGE_VERBS = [
		'require' => 'add',
		'install' => 'add',
		'i' => 'add',
		'add' => 'add',
		'update' => 'update',
		'up' => 'update',
		'upgrade' => 'update',
		'remove' => 'remove',
		'uninstall' => 'remove',
		'rm' => 'remove',
	];

	/**
	 * Parses one line.
	 *
	 * @param string $line
	 *   What the operator typed.
	 *
	 * @return array
	 *   Always has 'ok' and 'kind'. On success 'kind' is 'operation' or 'package'; an operation
	 *   carries op/args/writes/sliced/label, a package carries manager/verb/packages. On refusal it
	 *   carries 'error', plus 'refusal' when the command was recognised and declined.
	 */
	public static function parse(string $line): array
	{
		$words = self::words($line);
		while ($words !== [] && in_array(strtolower($words[0]), self::PREFIXES, true)) {
			array_shift($words);
		}
		if ($words === []) {
			return ['ok' => false, 'kind' => 'none', 'error' => 'nothing to run'];
		}

		$head = strtolower(array_shift($words));
		if (isset(self::REFUSED[$head])) {
			return [
				'ok' => false,
				'kind' => 'refused',
				'refusal' => $head,
				'error' => sprintf('%s is not available here: %s', $head, self::REFUSED[$head]),
			];
		}
		if (isset(self::PACKAGE_MANAGERS[$head])) {
			return self::parsePackage($head, $words);
		}
		return self::parseOperation($head, $words);
	}

	/**
	 * A drush-shaped operation.
	 *
	 * @param string $head
	 *   The command word.
	 * @param string[] $words
	 *   What followed it.
	 *
	 * @return array
	 *   The parse result.
	 */
	private static function parseOperation(string $head, array $words): array
	{
		$operations = OpsRegistry::operations();
		$op = self::DRUSH_ALIASES[$head] ?? ($operations[$head] ?? null ? $head : null);

		if ($op === null || !isset($operations[$op])) {
			return [
				'ok' => false,
				'kind' => 'unknown',
				'error' => sprintf(
					'unknown command "%s". Operations: %s. Package managers: %s',
					$head,
					implode(', ', array_keys($operations)),
					implode(', ', array_keys(self::PACKAGE_MANAGERS)),
				),
			];
		}

		$spec = $operations[$op];
		return [
			'ok' => true,
			'kind' => 'operation',
			'op' => $op,
			'typed' => $head,
			'args' => array_values(self::withoutFlags($words)),
			'flags' => array_values(self::onlyFlags($words)),
			'writes' => (bool) $spec['writes'],
			'sliced' => (bool) $spec['sliced'],
			'label' => (string) $spec['label'],
		];
	}

	/**
	 * A package-manager line.
	 *
	 * @param string $head
	 *   Executable name: `composer`, `npm`, `bun`, `yarn` or `pnpm`.
	 * @param string[] $words
	 *   What followed it.
	 *
	 * @return array
	 *   The parse result.
	 */
	private static function parsePackage(string $head, array $words): array
	{
		$registry = self::PACKAGE_MANAGERS[$head];
		$rest = self::withoutFlags($words);
		$verbWord = strtolower((string) array_shift($rest));
		$verb = self::PACKAGE_VERBS[$verbWord] ?? null;

		if ($verb === null) {
			return [
				'ok' => false,
				'kind' => 'unknown',
				'error' => sprintf(
					'%s %s is not something this terminal does. Try: %s',
					$head,
					$verbWord === '' ? '(nothing)' : $verbWord,
					implode(', ', array_unique(array_keys(self::PACKAGE_VERBS))),
				),
			];
		}

		// `composer install` with no packages means "restore the lock", which has no meaning where
		// the packed tree IS the vendor directory -- there is nothing to restore into
		if ($rest === [] && $verb === 'add') {
			return [
				'ok' => false,
				'kind' => 'unknown',
				'error' => sprintf(
					'%s %s needs at least one package name. Restoring a lock file has no meaning here: the packed tree is the vendor directory',
					$head,
					$verbWord,
				),
			];
		}

		return [
			'ok' => true,
			'kind' => 'package',
			'manager' => $registry,
			'typed' => $head,
			'verb' => $verb,
			'packages' => array_values(array_map([self::class, 'normalisePackage'], $rest)),
			'flags' => array_values(self::onlyFlags($words)),
			'writes' => true,
			// a fetch, an unpack and a per-file write cannot be one invocation
			'sliced' => true,
			'label' => sprintf('%s %s', $head, $verbWord),
		];
	}

	/**
	 * Splits `vendor/name:^1.2` into its parts.
	 *
	 * @param string $spec
	 *   One package argument.
	 *
	 * @return array
	 *   'name' and 'constraint', where constraint is NULL when none was given.
	 */
	public static function normalisePackage(string $spec): array
	{
		// rsplit on ':' so a scoped npm name (`@scope/pkg`) and a composer name both survive; an
		// npm `@scope/pkg@^1` uses '@' instead, and only the LAST one is the constraint
		$name = $spec;
		$constraint = null;
		if (str_contains($spec, ':')) {
			$at = strrpos($spec, ':');
			$name = substr($spec, 0, $at);
			$constraint = substr($spec, $at + 1);
		} elseif (($at = strrpos($spec, '@')) !== false && $at > 0) {
			$name = substr($spec, 0, $at);
			$constraint = substr($spec, $at + 1);
		}
		return ['name' => $name, 'constraint' => $constraint === '' ? null : $constraint];
	}

	/**
	 * The words that are not flags.
	 *
	 * @param string[] $words
	 *   The words.
	 *
	 * @return string[]
	 *   Those not starting with a dash.
	 */
	public static function withoutFlags(array $words): array
	{
		return array_values(array_filter($words, static fn($w) => !str_starts_with($w, '-')));
	}

	/**
	 * The words that are flags.
	 *
	 * @param string[] $words
	 *   The words.
	 *
	 * @return string[]
	 *   Those starting with a dash.
	 */
	public static function onlyFlags(array $words): array
	{
		return array_values(array_filter($words, static fn($w) => str_starts_with($w, '-')));
	}

	/**
	 * Splits a line into words, honouring quotes.
	 *
	 * Hand-rolled because `str_getcsv()` is the usual shortcut and gets this wrong: it treats a
	 * quote anywhere as opening a field. A quote here only opens a quoted run at a word BOUNDARY, so
	 * `en my"module other` is three words and an apostrophe inside a name cannot swallow the rest of
	 * the line.
	 *
	 * @param string $line
	 *   The raw line.
	 *
	 * @return string[]
	 *   The words, with the quotes that opened a run removed.
	 */
	public static function words(string $line): array
	{
		$words = [];
		$current = '';
		$quote = null;
		$started = false;
		$atBoundary = true;

		foreach (str_split(trim($line)) as $char) {
			if ($quote !== null) {
				if ($char === $quote) {
					$quote = null;
				} else {
					$current .= $char;
				}
				continue;
			}
			if (($char === '"' || $char === "'") && $atBoundary) {
				$quote = $char;
				$started = true;
				$atBoundary = false;
				continue;
			}
			if ($char === ' ' || $char === "\t") {
				if ($current !== '' || $started) {
					$words[] = $current;
				}
				$current = '';
				$started = false;
				$atBoundary = true;
				continue;
			}
			$current .= $char;
			$atBoundary = false;
		}
		if ($current !== '' || $started) {
			$words[] = $current;
		}
		return $words;
	}

	/**
	 * The one-line summary the form shows before anything runs.
	 *
	 * A SLICED operation is not refused, it is HANDED OFF. `cr` is 282.9 ms of
	 * `drupal_flush_all_caches()` in wasm, which is 28x a free-plan invocation, so running it inline
	 * would exceed the budget rather than fail cleanly.
	 *
	 * @param array $parsed
	 *   A successful {@see parse} result.
	 *
	 * @return string
	 *   What will happen.
	 */
	public static function plan(array $parsed): string
	{
		if (($parsed['kind'] ?? '') === 'package') {
			$names = implode(', ', array_column($parsed['packages'], 'name'));
			$verb = [
				'add' => 'Resolve and install',
				'update' => 'Resolve and update',
				'remove' => 'Remove',
			][$parsed['verb']];
			return sprintf(
				'%s %s. The host fetches and unpacks it across background invocations; nothing is compiled and no package script runs.',
				$verb,
				$names,
			);
		}

		$parts = [$parsed['label']];
		if (($parsed['writes'] ?? false) === true) {
			$parts[] = 'It writes.';
		}
		$parts[] =
			($parsed['sliced'] ?? false) === true
				? 'It is driven across alarm invocations rather than run inline, so it completes in the background.'
				: 'It runs inline.';
		return implode(' ', $parts);
	}
}
