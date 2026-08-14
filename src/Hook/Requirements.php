<?php

declare(strict_types=1);

namespace Drupal\drupflare\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupflare\Host;
use Drupal\drupflare\StreamWrapper\CfwFileStreamWrapper;

/**
 * Status-report entries for the Drupflare compatibility layer.
 *
 * WHAT THE FIRST ENTRY IS FOR, because it is the one that earns its place. On this runtime the
 * NATIVE http/https wrapper is advertised by `stream_get_wrappers()` and reading through it
 * throws a JavaScript `ReferenceError: Asyncify is not defined` from inside the wasm import.
 * That is not a PHP exception: `@` does not suppress it, `catch (Throwable)` never sees it, and
 * the whole invocation dies. So one `file_get_contents('https://...')` anywhere in vendor or
 * contrib code is an uncatchable invocation-killer, and whether this module's wrapper is in place
 * is the difference between a working site and one that dies on a page nobody changed.
 *
 * `drupflare.module` registers it on every request. This reports whether that actually took, so a
 * host that loads modules some other way finds out from the status report instead of from a
 * 500 on an unrelated page.
 */
final class Requirements
{
	/**
	 * Host functions this module can use, in the order they matter to a site.
	 *
	 * Shared with the install-time check so the two cannot disagree about what "installed" means.
	 */
	public const CAPABILITIES = ['cfwMail', 'cfwLog', 'cfwFetch', 'cfwImageUrl', 'cfwFileWrite'];

	/**
	 * Implements hook_runtime_requirements().
	 *
	 * @return array
	 *   The status report entries.
	 */
	#[Hook('runtime_requirements')]
	public function runtimeRequirements(): array
	{
		$requirements = [];

		$registered = stream_get_wrappers();
		$missing = array_values(array_diff(['http', 'https'], $registered));
		$requirements['drupflare_stream_wrapper'] = [
			'title' => new TranslatableMarkup('Drupflare outbound HTTP wrapper'),
			'value' =>
				$missing === []
					? new TranslatableMarkup('Registered for http and https')
					: new TranslatableMarkup('Not registered for: @schemes', [
						'@schemes' => implode(', ', $missing),
					]),
			'description' =>
				$missing === []
					? null
					: new TranslatableMarkup(
						'drupflare.module registers this wrapper at include time, so a scheme missing here means the module file did not load. Until it does, any code opening an @schemes:// URL directly will kill the invocation rather than raise a catchable error.',
						['@schemes' => reset($missing)],
					),
			'severity' => $missing === [] ? RequirementSeverity::OK : RequirementSeverity::Error,
		];

		$installed = array_values(array_filter(self::CAPABILITIES, Host::has(...)));
		$absent = array_values(array_diff(self::CAPABILITIES, $installed));
		$requirements['drupflare_capabilities'] = [
			'title' => new TranslatableMarkup('Drupflare host capabilities'),
			'value' => new TranslatableMarkup('@count of @total installed', [
				'@count' => count($installed),
				'@total' => count(self::CAPABILITIES),
			]),
			// a partial set is the normal shape rather than a fault: which capabilities exist
			// follows which bindings a deployment configured
			'description' =>
				$absent === []
					? null
					: new TranslatableMarkup('Not installed by this deployment: @absent', [
						'@absent' => implode(', ', $absent),
					]),
			'severity' =>
				$installed === [] ? RequirementSeverity::Warning : RequirementSeverity::OK,
		];

		if (CfwFileStreamWrapper::available()) {
			// public:// and private:// belong to core, and StreamWrapperManager::register() runs
			// AFTER modules load, so this reports what is in force rather than what this module
			// would prefer
			$owned = array_values(array_intersect(CfwFileStreamWrapper::SCHEMES, $registered));
			$requirements['drupflare_file_wrapper'] = [
				'title' => new TranslatableMarkup('Drupflare durable file storage'),
				'value' =>
					$owned === []
						? new TranslatableMarkup('No scheme is registered')
						: new TranslatableMarkup('Registered for @schemes', [
							'@schemes' => implode(', ', $owned),
						]),
				'description' => new TranslatableMarkup(
					'The host installed the file capability. Which class serves public:// is decided by the container, because Drupal re-registers core stream wrappers after modules load; this module does not claim those schemes from a module file, where the claim would be silently replaced.',
				),
				'severity' => RequirementSeverity::OK,
			];
		}

		return $requirements;
	}
}
