<?php

declare(strict_types=1);

namespace Drupal\Core\Form {
	/**
	 * A FormState with the static renamed, which is the whole point of this fixture.
	 */
	class FormState
	{
		/**
		 * Deliberately NOT called `anyErrors`.
		 *
		 * @var bool
		 */
		protected static $errorFlag = true;
	}
}

namespace {
	use Drupal\Core\DependencyInjection\ContainerBuilder;
	use Drupal\drupflare\RequestResetter;

	// the same two candidates health-suite.php searches, because this file runs from a checkout
	// that has its own vendor/ AND from the worker's gate, where the sibling is checked out beside
	// a full Drupal tree and composer never runs
	$module = dirname(__DIR__, 2);
	$candidates = [
		$module . '/vendor/autoload.php',
		dirname(__DIR__, 4) . '/drupal-src/vendor/autoload.php',
	];
	foreach ($candidates as $candidate) {
		if (is_file($candidate)) {
			require_once $candidate;
			break;
		}
	}

	// only composer's autoload maps this namespace, so the fallback tree needs it supplied
	spl_autoload_register(function (string $class) use ($module): void {
		$prefix = 'Drupal\\drupflare\\';
		if (!str_starts_with($class, $prefix)) {
			return;
		}
		$file =
			$module . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	});

	if (!class_exists(ContainerBuilder::class)) {
		fwrite(STDERR, "no autoloader reached: neither vendor/ nor a sibling drupal-src\n");
		exit(3);
	}

	if ((new ReflectionClass('Drupal\Core\Form\FormState'))->hasProperty('anyErrors')) {
		fwrite(STDERR, "the stub did not win the name; composer answered first\n");
		exit(2);
	}

	$log = (new RequestResetter(new ContainerBuilder(), []))->reset();
	echo 'form_errors_reset=', $log['form_errors_reset'] ? 'true' : 'false', "\n";
	echo 'html_seen_ids_reset=', $log['html_seen_ids_reset'] ?? false ? 'true' : 'false', "\n";
	echo 'drupal_static_reset=', $log['drupal_static_reset'] ?? false ? 'true' : 'false', "\n";
	exit(0);
}
