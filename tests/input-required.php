<?php

declare(strict_types=1);

namespace Drupal\filter {
	interface FilterFormatRepositoryInterface {}

	final class StubFormatRepository implements FilterFormatRepositoryInterface {}
}

namespace Drupal\views\Plugin\views\exposed_form {
	class ExposedFormPluginBase
	{
		public $view;
		public $displayHandler;
		public int $parentQueries = 0;

		public function __construct(array $configuration, $plugin_id, $plugin_definition) {}

		public function query()
		{
			// the parent is stubbed so the test can count whether InputRequired reached it
			$this->parentQueries++;
		}
	}
}

namespace {
	use Drupal\filter\StubFormatRepository;
	use Drupal\views\Plugin\views\exposed_form\InputRequired;

	final class StubFilter
	{
		public array $options;

		public function __construct(string $identifier)
		{
			$this->options = ['expose' => ['identifier' => $identifier]];
		}

		public function isExposed(): bool
		{
			return true;
		}
	}

	final class StubView
	{
		public array $filter;
		public array $result = [['row' => 1]];
		public bool $built = false;
		public bool $executed = false;

		public function __construct(private array $exposedInput)
		{
			$this->filter = [new StubFilter('title')];
		}

		public function getExposedInput(): array
		{
			return $this->exposedInput;
		}
	}

	$pass = 0;
	$fail = 0;

	function ok(string $label, bool $condition): void
	{
		global $pass, $fail;
		if ($condition) {
			$pass++;
			echo "  ok   $label\n";
		} else {
			$fail++;
			echo "  FAIL $label\n";
		}
	}

	function sequence(string $order): void
	{
		// the static in exposedFilterApplied() is set by the first request and never cleared, so
		// each order needs its own process; the driver below spawns one per order.
		$steps = $order === 'filled-first' ? [true, false, true] : [false, true, false];
		foreach ($steps as $i => $filled) {
			$view = new StubView($filled ? ['title' => 'x'] : []);
			$plugin = new InputRequired([], 'input_required', [], new StubFormatRepository());
			$plugin->view = $view;
			$plugin->query();

			$label = "$order, request " . ($i + 1) . ($filled ? ' (filled)' : ' (empty)');
			if ($filled) {
				ok("$label reaches the query", $plugin->parentQueries === 1 && !$view->built);
			} else {
				ok(
					"$label is forced to no results",
					$plugin->parentQueries === 0 && $view->built && $view->result === [],
				);
			}
		}
	}

	$root = $argv[1] ?? (getenv('DRUPAL_ROOT') ?: null);
	$file = '/core/modules/views/src/Plugin/views/exposed_form/InputRequired.php';
	if ($root === null || !is_file($root . $file)) {
		fwrite(STDERR, "usage: php tests/input-required.php <drupal-root> (or set DRUPAL_ROOT)\n");
		exit(2);
	}

	if (isset($argv[2])) {
		require_once $root . $file;
		sequence($argv[2]);
		echo "\n$pass passed, $fail failed\n";
		exit($fail === 0 ? 0 : 1);
	}

	$failed = false;
	foreach (['empty-first', 'filled-first'] as $order) {
		$output = [];
		$command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, $root, $order]));
		exec($command . ' 2>&1', $output, $status);
		echo implode("\n", $output) . "\n";
		$failed = $failed || $status !== 0;
	}
	exit($failed ? 1 : 0);
}
