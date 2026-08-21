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

	require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
