<?php

/**
 * @file
 * Runs one of this module's suites under a coverage driver and writes reports.
 *
 * The suites are plain PHP scripts rather than PHPUnit, so there is no runner to ask for
 * coverage. This wraps one: start collection, require the suite, and write the reports from a
 * shutdown handler because every suite ends with exit().
 *
 * One suite per run, and that is a property of the suites rather than a limitation here. Both end
 * in exit(), so requiring the second after the first is dead code. They also need different
 * things: load-classes.php needs a real Drupal root, health-suite.php needs none. Two runs
 * produce two clover files, and Codecov merges reports across uploads -- see
 * `.github/workflows/coverage.yml`, which uploads each under its own flag.
 *
 * Only src/ is measured. tests/ is the instrument, and measuring the instrument inflates the
 * number without covering anything a consumer installs.
 *
 * Usage:
 *   php tests/coverage.php health
 *   php tests/coverage.php classes /path/to/drupal-root
 *
 * Exits 2 without running anything when the suite is unknown, the Drupal root is missing for a
 * suite that needs one, or no coverage driver is loaded -- so a CI job cannot report a pass it
 * did not measure.
 */

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Node\Directory;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;

// the shutdown handler registered below is what keeps the collector alive, so this returns
// nothing and leaves no unused handle behind
$suiteFile = (static function (): string {
	$repo = dirname(__DIR__);
	$suite = $GLOBALS['argv'][1] ?? '';
	$root = $GLOBALS['argv'][2] ?? getenv('DRUPAL_ROOT') ?: null;

	// the suites this can drive, and whether each needs a Drupal root
	$suites = [
		'classes' => ['file' => 'load-classes.php', 'needsRoot' => true],
		'health' => ['file' => 'health-suite.php', 'needsRoot' => false],
	];

	// no suite named means every suite, one child process each. The child is forced rather than
	// chosen: both suites end in exit() and both declare ok(), so requiring the second after the
	// first is dead code AND a fatal redeclare. `bun run test:coverage` passes no argument, so
	// without this the package script exits 2 and measures nothing -- which is the same defect
	// class as a runner that silently executes one suite of three.
	if ($suite === '') {
		$failed = 0;
		foreach (array_keys($suites) as $name) {
			$argv = [PHP_BINARY, __FILE__, $name];
			if ($suites[$name]['needsRoot'] && $root !== null) {
				$argv[] = $root;
			}
			$status = 0;
			$output = [];
			echo "\n== $name ==\n";
			exec(implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1', $output, $status);
			echo implode("\n", $output), "\n";
			if ($status !== 0) {
				$failed++;
				fwrite(STDERR, "the $name suite exited $status\n");
			}
		}
		exit($failed === 0 ? 0 : 1);
	}

	if (!isset($suites[$suite])) {
		fwrite(
			STDERR,
			'Pass a suite name: ' .
				implode(', ', array_keys($suites)) .
				", or none to run them all.\n" .
				"Usage: php tests/coverage.php [<suite>] [/path/to/drupal-root]\n",
		);
		exit(2);
	}
	if (!is_file($repo . '/vendor/autoload.php')) {
		fwrite(STDERR, "Run composer install first; vendor/autoload.php is missing.\n");
		exit(2);
	}
	$needsRoot = $suites[$suite]['needsRoot'];
	if ($needsRoot && ($root === null || !is_file($root . '/vendor/autoload.php'))) {
		fwrite(
			STDERR,
			"The $suite suite needs a Drupal 11.3+ root with vendor/ installed.\n" .
				"Pass it as argv[2] or set DRUPAL_ROOT.\n" .
				"Refusing to report coverage for a suite that cannot run.\n",
		);
		exit(2);
	}
	if (!extension_loaded('xdebug') && !extension_loaded('pcov')) {
		fwrite(
			STDERR,
			"No coverage driver: install xdebug or pcov.\n" .
				"Refusing to write an empty report that would read as 0% rather than as unmeasured.\n",
		);
		exit(2);
	}

	// order matters: the Drupal root's autoloader has to register Drupal\Core first, or this
	// repo's own vendored drupal/core answers instead and the suite runs against a different
	// core than the one it was pointed at. Composer's autoload file is idempotent, so the suite
	// requiring it again gets this same loader back
	if ($needsRoot) {
		require $root . '/vendor/autoload.php';
	}
	require $repo . '/vendor/autoload.php';

	$filter = new Filter();
	$walk = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($repo . '/src', FilesystemIterator::SKIP_DOTS),
	);
	$measured = [];
	foreach ($walk as $file) {
		if ($file->isFile() && $file->getExtension() === 'php') {
			$measured[] = $file->getPathname();
		}
	}
	sort($measured);
	if ($measured === []) {
		fwrite(STDERR, "No PHP files under $repo/src to measure.\n");
		exit(2);
	}
	$filter->includeFiles($measured);

	$out = $repo . '/coverage';
	if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
		fwrite(STDERR, "Could not create $out.\n");
		exit(2);
	}

	$coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
	$coverage->start($suite);

	// the suite ends in exit(), which still runs shutdown handlers, so this is the only place
	// the reports can be written from without editing the suite
	register_shutdown_function(static function () use ($coverage, $out, $suite): void {
		$coverage->stop();
		try {
			$executed = $coverage->getReport()->numberOfExecutedLines();
		} catch (Throwable $e) {
			fwrite(STDERR, "\nCoverage report failed: " . $e->getMessage() . "\n");
			return;
		}
		// a suite that refused to run reaches here too, and a 0% clover reads as "measured and
		// bad" rather than "never ran" -- which is the one thing this file exists to prevent.
		// exit() from a shutdown handler sets the status, so CI sees the refusal
		if ($executed === 0) {
			fwrite(
				STDERR,
				"\nNo line of src/ was executed, so the $suite suite did not run.\n" .
					"Refusing to write a 0% report.\n",
			);
			exit(2);
		}
		// php-code-coverage 12 takes a Node\Directory where 10 took the collector, and the writers
		// moved at different times -- so ask each writer's own signature rather than the library
		// version. `rom` already does this; stream-http and this file did not, and CI failed with
		// "Argument #1 ($report) must be of type Node\Directory, CodeCoverage given".
		$node = null;
		$reportFor = static function (object $writer) use (
			$coverage,
			&$node,
		): CodeCoverage|Directory {
			$first = (new ReflectionMethod($writer, 'process'))->getParameters()[0] ?? null;
			$type = $first?->getType();
			if ($type instanceof ReflectionNamedType && $type->getName() === Directory::class) {
				// getReport() walks the whole tree, so it is built at most once
				return $node ??= $coverage->getReport();
			}
			return $coverage;
		};

		try {
			$clover = new Clover();
			$text = new Text(Thresholds::default(), false, true);
			$clover->process($reportFor($clover), $out . "/drupflare-$suite.clover.xml");
			$summary = $text->process($reportFor($text), false);
		} catch (Throwable $e) {
			fwrite(STDERR, "\nCoverage report failed: " . $e->getMessage() . "\n");
			return;
		}
		file_put_contents($out . "/drupflare-$suite.coverage.txt", $summary);
		echo "\n" . $summary;
		echo "wrote $out/drupflare-$suite.clover.xml and $out/drupflare-$suite.coverage.txt\n";
	});

	// the suite reads its OWN argv, where argv[1] is the Drupal root rather than a suite name;
	// leaving this shell's argv in place makes load-classes.php read "classes" as a root, refuse,
	// and hand back a 0% report
	$GLOBALS['argv'] = $needsRoot ? [$GLOBALS['argv'][0], $root] : [$GLOBALS['argv'][0]];
	$GLOBALS['argc'] = count($GLOBALS['argv']);

	return __DIR__ . '/' . $suites[$suite]['file'];
})();

require $suiteFile;
