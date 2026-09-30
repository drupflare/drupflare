<?php

/**
 * @file
 * Drives Symfony Process under disable_functions, with the router's functions declared.
 */

// the same two candidates health-suite.php searches: the worker's gate checks this repo out beside
// a full Drupal tree and composer never runs, so there is no vendor/ of its own
$module = dirname(__DIR__, 2);
foreach (
	[$module . '/vendor/autoload.php', dirname(__DIR__, 4) . '/drupal-src/vendor/autoload.php']
	as $candidate
) {
	if (is_file($candidate)) {
		require_once $candidate;
		break;
	}
}
// only composer's autoload maps this namespace, so the fallback tree needs it supplied
spl_autoload_register(function (string $class) use ($module): void {
	$prefix = 'Drupal\\drupflare\\';
	if (str_starts_with($class, $prefix)) {
		$file =
			$module . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});
require __DIR__ . '/exec-functions.php';

use Drupal\drupflare\Exec\Router;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

Router::useShared(new Router());
$out = [];

$p = new Process(['echo', 'from', 'symfony']);
$p->mustRun();
$out['echo'] = $p->getOutput();
$out['echoCode'] = $p->getExitCode();

$p = Process::fromShellCommandline('sha256sum');
$p->setInput('abc');
$p->mustRun();
$out['stdin'] = $p->getOutput();

$p = new Process(['base64', '-w', '0']);
$p->setInput('drupflare');
$p->mustRun();
$out['base64'] = $p->getOutput();

$p = new Process(['false']);
try {
	$p->mustRun();
	$out['failed'] = 'no exception';
} catch (ProcessFailedException) {
	$out['failed'] = 'threw ' . $p->getExitCode();
}

$p = new Process(['cat', '/etc/hostname']);
try {
	$p->mustRun();
	$out['unknown'] = 'no exception';
} catch (Throwable $e) {
	$out['unknown'] =
		get_class($e) === ProcessFailedException::class
			? 'failed ' . $p->getExitCode()
			: get_class($e);
}

$p = new Process(['sh', '-c', 'echo a | wc -c']);
$p->run();
$out['shell'] = $p->getExitCode();

echo json_encode($out);
