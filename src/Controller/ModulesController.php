<?php

declare(strict_types=1);

namespace Drupal\drupflare\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\drupflare\Host;
use Exception;

/**
 * Where code comes from on this platform, as a tab on the modules page.
 *
 * Composer never runs here, so `composer require` delivers nothing and the usual answer to "how do
 * I add a module" is wrong. A Drupal administrator looking for that answer looks at
 * `/admin/modules`, which is why this is a local task there rather than a page somewhere else.
 *
 * IT DELIBERATELY DOES NOT REIMPLEMENT THE DELIVERY FORMS. The owner surface already carries git
 * remotes, registry install and tree upload, and a second implementation of each is two things that
 * drift apart. What was missing was a page inside Drupal that names the three paths, says which one
 * fits, and reports what this site currently holds.
 */
final class ModulesController extends ControllerBase
{
	/**
	 * Asks the host what it holds.
	 *
	 * @return array|null
	 *   The decoded reply, or NULL when this is not running inside a Worker.
	 */
	private static function delivered(): ?array
	{
		try {
			$reply = Host::call('cfwModules', ['action' => 'list']);
		} catch (Exception) {
			return null;
		}
		return is_array($reply) && !empty($reply['ok']) ? $reply : null;
	}

	/**
	 * The page.
	 *
	 * @return array
	 *   A render array.
	 */
	public function page(): array
	{
		$build = [];
		$build['intro'] = [
			'#markup' =>
				'<p>' .
				$this->t(
					'Composer does not run on this platform, so a module arrives as files the runtime mounts. There are three ways to deliver them, and each needs the owner token rather than a Drupal account.',
				) .
				'</p>',
		];

		$build['paths'] = [
			'#theme' => 'table',
			'#header' => [$this->t('Path'), $this->t('Use it When'), $this->t('Keeps History')],
			'#rows' => [
				[
					$this->t('Git remote'),
					$this->t('The module already lives in a repository you control.'),
					$this->t('Yes, through the remote'),
				],
				[
					$this->t('Registry install'),
					$this->t('The module is published and you want a released version.'),
					$this->t('No'),
				],
				[
					$this->t('Tree upload'),
					$this->t('The module is on your disk and is not published anywhere.'),
					$this->t('Yes, five revisions'),
				],
			],
		];

		$held = self::delivered();
		if ($held === null) {
			$build['none'] = [
				'#markup' =>
					'<p>' .
					$this->t(
						'This site is not running inside a Drupflare Worker, so there is nothing to report.',
					) .
					'</p>',
			];
			return $build;
		}

		$rows = [];
		foreach ((array) ($held['packages'] ?? []) as $package) {
			$rows[] = [
				(string) ($package['name'] ?? ''),
				(string) ($package['source'] ?? ''),
				(string) ($package['revisions'] ?? ''),
				(string) ($package['active'] ?? ''),
			];
		}

		$build['held'] = [
			'#type' => 'details',
			'#title' => $this->t('Delivered to This Site'),
			'#open' => true,
			'table' => [
				'#theme' => 'table',
				'#header' => [
					$this->t('Package'),
					$this->t('Source'),
					$this->t('Revisions'),
					$this->t('Active'),
				],
				'#rows' => $rows,
				'#empty' => $this->t('Nothing has been delivered to this site yet.'),
			],
		];

		$build['where'] = [
			'#markup' =>
				'<p>' .
				$this->t(
					'The delivery forms are on the owner surface at <code>/_cfw/git</code>, which needs the owner token.',
				) .
				'</p>',
		];
		return $build;
	}
}
