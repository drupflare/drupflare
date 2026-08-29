<?php

declare(strict_types=1);

namespace Drupal\drupflare\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;
use Drupal\drupflare\Password\CfwPassword;
use Drupal\drupflare\StreamWrapper\CfwFileStreamWrapper;
use Throwable;
use XMLWriter;

/**
 * Status-report entries for the Drupflare compatibility layer.
 *
 * On this runtime the
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
	public const CAPABILITIES = [
		'cfwMail',
		'cfwLog',
		'cfwFetch',
		'cfwTcp',
		'cfwImageUrl',
		'cfwFileWrite',
	];

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
		} else {
			// A ROW EITHER WAY. This used to exist only INSIDE the branch, so a deployment without
			// the file capability got no row at all about file storage -- uploads land in MEMFS and
			// vanish on eviction, reported nowhere. An absent capability is the case that most
			// needs saying.
			$requirements['drupflare_file_wrapper'] = [
				'title' => new TranslatableMarkup('Drupflare durable file storage'),
				'value' => new TranslatableMarkup('Not available'),
				'description' => new TranslatableMarkup(
					'The host did not install the file capability, so uploaded files are written to the in-memory filesystem and are lost when this object is evicted. Nothing else reports this.',
				),
				'severity' => RequirementSeverity::Error,
			];
		}

		// gd is not compiled in, so `cfw_images` is the only toolkit a site can have. A missing
		// one is silent otherwise: every derivative fails and nothing on the status page says so
		$toolkits = [];
		try {
			$toolkits = array_keys(
				\Drupal::service('image.toolkit.manager')->getAvailableToolkits(),
			);
		} catch (Throwable $e) {
			$toolkits = [];
		}
		$requirements['drupflare_image_toolkit'] = [
			'title' => new TranslatableMarkup('Drupflare image toolkit'),
			'value' =>
				$toolkits === []
					? new TranslatableMarkup('None available')
					: new TranslatableMarkup('Available: @ids', [
						'@ids' => implode(', ', $toolkits),
					]),
			'description' =>
				$toolkits === []
					? new TranslatableMarkup(
						'No image toolkit is available, so every image style silently produces nothing. This build has no gd, so cfw_images is the only toolkit that can serve; check that the Drupflare module is enabled and that the host exposes cfwImageUrl.',
					)
					: null,
			'severity' => $toolkits === [] ? RequirementSeverity::Error : RequirementSeverity::OK,
		];

		// anything the host could neither shim nor accommodate reports itself here rather
		// than being silently absent. Merged last so a declaration cannot displace a fixed row
		return $requirements + Degradation::requirements();
	}

	/**
	 * Corrects status-report rows that describe a php.ini this runtime does not have.
	 *
	 * `extension_loaded()` IS A BUILT-IN, so the conditional-declaration pattern every other shim
	 * here uses cannot bind to it -- the same wall `password_hash()` hit, and the reason argon2
	 * needed a service decorator. A module asking `extension_loaded('xmlwriter')` therefore gets
	 * FALSE no matter how complete the replacement is, and `simple_sitemap_requirements()` turns
	 * that into a `RequirementSeverity::Error` that blocks installation outright.
	 *
	 * `hook_requirements_alter()` is the seam Drupal provides for exactly this, so the fix stays
	 * host-side and the modules are unmodified. Every row here is REPLACED rather than removed, and
	 * one that reports a real loss keeps a warning: an operator reading the status report has to be
	 * able to tell what this platform supplies from what it genuinely cannot.
	 *
	 * @param array $requirements
	 *   Every requirement Drupal collected, by key.
	 */
	#[Hook('requirements_alter')]
	public function requirementsAlter(array &$requirements): void
	{
		$this->alterSitemapExtensions($requirements);
		$this->alterPlatformRows($requirements);
	}

	/**
	 * Replaces core rows that describe a php.ini this runtime does not have.
	 *
	 * NONE OF THESE IS MUTED. Each one is a true statement about a stock PHP host and a misleading
	 * one here, so each is replaced with what is actually the case and why. A row that reports a
	 * genuine loss keeps its severity: `gd` really is absent and code calling `imagecreate*`
	 * directly really will fail, so it stays a warning rather than becoming an OK.
	 *
	 * @param array $requirements
	 *   Every requirement Drupal collected, by key.
	 */
	private function alterPlatformRows(array &$requirements): void
	{
		// core marks a missing gd an Error because it assumes image styles cannot work. Here they
		// do: `cfw_images` is a toolkit backed by the host, so derivatives are served
		if (isset($requirements['php_extensions']) && !extension_loaded('gd')) {
			$requirements['php_extensions'] = [
				'title' => new TranslatableMarkup('PHP extensions'),
				'value' => new TranslatableMarkup('Enabled, except gd'),
				'description' => new TranslatableMarkup(
					'This runtime is PHP compiled to WebAssembly and carries no gd. Image styles and derivatives work through the cfw_images toolkit, which resizes at the edge instead. Contributed code that calls the gd functions directly will still fail, so gd is reported rather than hidden.',
				),
				'severity' => RequirementSeverity::Warning,
			];
		}

		// the mb_* functions exist, supplied by the host; `extension_loaded()` reports COMPILED
		// extensions and cannot be shimmed, which is the same wall the xmlwriter row hits
		if (isset($requirements['unicode']) && !extension_loaded('mbstring')) {
			$requirements['unicode'] = [
				'title' => new TranslatableMarkup('Unicode library'),
				'value' => new TranslatableMarkup('Provided by Drupflare'),
				'description' => new TranslatableMarkup(
					'There is no ext-mbstring in this build. Drupflare supplies the mb_* functions the site uses, checked against a Unicode corpus; extension_loaded() still answers FALSE because it reports compiled extensions.',
				),
				'severity' => RequirementSeverity::OK,
			];
		}

		// the host buffers the whole response by construction -- it is captured and returned as one
		// body, so there is no streaming write for output buffering to make cheaper
		if (isset($requirements['output_buffering'])) {
			$requirements['output_buffering'] = [
				'title' => new TranslatableMarkup('Output Buffering'),
				'value' => new TranslatableMarkup('Not applicable'),
				'description' => new TranslatableMarkup(
					'A Worker returns one response body rather than streaming to a socket, so the whole render is buffered by the host whatever this setting says.',
				),
				'severity' => RequirementSeverity::OK,
			];
		}

		// core tells the operator to install ext-argon2, which is not a thing that can be done to a
		// wasm build. The capability exists and is a host-side switch, so the row names the switch.
		// Three states rather than two, because `CfwPassword` distinguishes a missing bridge from an
		// operator who has not turned it on and only one of those is actionable
		if (
			isset($requirements['password_hashing']) &&
			!in_array('argon2id', password_algos(), true)
		) {
			$bridge = CfwPassword::bridgeAvailable();
			$enabled = (bool) Settings::get('drupflare.argon2', false);
			if ($bridge && $enabled) {
				$value = new TranslatableMarkup('Passwords are hashed with argon2id');
				$description = new TranslatableMarkup(
					'Hashing runs on the host at m=19456 KiB, t=2, p=1, and is written in the same $argon2id$ encoding ext-argon2 produces, so the hashes stay verifiable off this platform. password_algos() still lists only bcrypt because it reports compiled extensions.',
				);
			} elseif ($bridge) {
				$value = new TranslatableMarkup('bcrypt, with argon2id available');
				$description = new TranslatableMarkup(
					'There is no ext-argon2 in this build, so core recommends installing one that cannot be installed. Drupflare hashes with argon2id on the host instead; set the ARGON2 variable to turn it on. Existing bcrypt hashes keep working and each account is upgraded at its next login.',
				);
			} else {
				$value = new TranslatableMarkup('bcrypt');
				$description = new TranslatableMarkup(
					'There is no ext-argon2 in this build and this deployment did not install the host argon2 capability, so bcrypt is what is available. Installing that capability is what makes argon2id reachable.',
				);
			}
			$requirements['password_hashing'] = [
				'title' => new TranslatableMarkup('Password hashing'),
				'value' => $value,
				'description' => $description,
				'severity' => RequirementSeverity::OK,
			];
		}

		// opcache is compiled in and deliberately DISABLED, which is why this row is honest and only
		// its reason is missing: measured on this interpreter it bought no render time and cost
		// ~37 MiB of the 128 MiB an isolate gets
		if (isset($requirements['php_opcache']) && extension_loaded('Zend OPcache')) {
			$requirements['php_opcache'] = [
				'title' => new TranslatableMarkup('PHP OPcode caching'),
				'value' => new TranslatableMarkup('Disabled deliberately'),
				'description' => new TranslatableMarkup(
					'OPcache is compiled into this interpreter and switched off. Measured here it changed render time by about a millisecond and cost roughly 37 MiB of the 128 MiB this isolate has, which is memory the site needs to render at all.',
				),
				'severity' => RequirementSeverity::OK,
			];
		}
	}

	/**
	 * Clears an install-blocking `ext-xmlwriter` error when the host supplies the class instead.
	 *
	 * @param array $requirements
	 *   Every requirement Drupal collected, by key.
	 */
	private function alterSitemapExtensions(array &$requirements): void
	{
		if (!isset($requirements['simple_sitemap_php_extensions'])) {
			return;
		}
		if (extension_loaded('xmlwriter') || !self::xmlWriterUsable()) {
			return;
		}

		$requirements['simple_sitemap_php_extensions'] = [
			'title' => new TranslatableMarkup('Simple XML Sitemap PHP extensions'),
			'value' => new TranslatableMarkup('Provided by Drupflare'),
			'description' => new TranslatableMarkup(
				'This runtime carries no ext-xmlwriter. Drupflare supplies a pure-PHP XMLWriter with the same output, verified byte for byte against libxml, so sitemap generation works; extension_loaded() still answers FALSE because it reports compiled extensions and cannot be shimmed.',
			),
			'severity' => RequirementSeverity::OK,
		];
	}

	/**
	 * Whether the replacement class is present AND produces a document.
	 *
	 * Checking `class_exists()` alone would pass on a stub, which is the failure this project keeps
	 * finding: a capability that reports itself present and does nothing. One round trip is cheap
	 * and only runs when a module actually asked.
	 *
	 * @return bool
	 *   TRUE when a sitemap can really be written.
	 */
	private static function xmlWriterUsable(): bool
	{
		if (!class_exists('XMLWriter', false)) {
			return false;
		}
		try {
			$writer = new XMLWriter();
			$writer->openMemory();
			$writer->writeElement('loc', 'https://example.com/');
			return $writer->outputMemory() === '<loc>https://example.com/</loc>';
		} catch (Throwable) {
			return false;
		}
	}
}
