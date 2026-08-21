<?php

declare(strict_types=1);

namespace Drupal\drupflare\Install\Requirements;

use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupflare\Hook\Requirements;
use Drupal\drupflare\Host;

/**
 * Install-time requirements for the Drupflare compatibility layer.
 *
 * Drupal 11.3 deprecates a procedural
 * `<module>_requirements()` that carries no `#[LegacyRequirementsHook]` attribute, and splits
 * the phases: `core/includes/install.inc:853` scans `src/Install/Requirements/` for this
 * interface, and the status report goes through `#[Hook('runtime_requirements')]` instead.
 *
 * Nothing here is an error. Every capability this module provides is satisfied by
 * a host function the runtime installs, and a deployment that installs none of them still gets a
 * module that refuses loudly at the point of use rather than one that fails to install. Returning
 * RequirementSeverity::Error would make the module uninstallable on a plain PHP host, which is
 * exactly where someone would install it to read the code.
 *
 * @see Requirements
 */
final class DrupflareRequirements implements InstallRequirementsInterface
{
	/**
	 * {@inheritdoc}
	 */
	public static function getRequirements(): array
	{
		$installed = array_values(array_filter(Requirements::CAPABILITIES, Host::has(...)));

		return [
			'drupflare_host' => [
				'title' => new TranslatableMarkup('Drupflare host runtime'),
				'value' =>
					$installed === []
						? new TranslatableMarkup('No host capabilities are installed')
						: new TranslatableMarkup('@count of @total host capabilities installed', [
							'@count' => count($installed),
							'@total' => count(Requirements::CAPABILITIES),
						]),
				'description' =>
					$installed === []
						? new TranslatableMarkup(
							'This module bridges Drupal to Cloudflare Workers bindings, and none of those bindings is reachable here. Installing is allowed: every class refuses at the point of use with the capability it wanted named, which is more useful than a blocked install.',
						)
						: null,
				// Warning rather than Error: see the class docblock
				'severity' =>
					$installed === [] ? RequirementSeverity::Warning : RequirementSeverity::OK,
			],
		];
	}
}
