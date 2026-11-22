<?php

/**
 * The newest core version WordPress knows of, when it is newer than the one running.
 */
function drupflare_updates_offer(): ?string
{
	global $wp_version;
	$current = get_site_transient('update_core');
	if (!is_object($current) || empty($current->updates) || !is_array($current->updates)) {
		return null;
	}
	foreach ($current->updates as $update) {
		if (
			is_object($update) &&
			($update->response ?? '') === 'upgrade' &&
			isset($update->current) &&
			version_compare((string) $update->current, (string) $wp_version, '>')
		) {
			return (string) $update->current;
		}
	}
	return null;
}

function drupflare_updates_explanation(): string
{
	global $wp_version;
	$text = sprintf(
		'This site runs WordPress %s. Core, plugin and theme files are read-only here (DISALLOW_FILE_MODS is on), so no screen in this dashboard installs or updates them.',
		$wp_version,
	);
	$offer = drupflare_updates_offer();
	if ($offer !== null) {
		$text .= sprintf(' WordPress %s is available.', $offer);
	}
	return $text .
		' The platform applies a core update by delivering a new WordPress pack. A maintainer installs, updates and rolls back plugins and themes through the host operation at /wp-packages, which keeps a receipt and the previous version.';
}

/**
 * The admin screens that install or update files.
 *
 * @return list<string>
 */
function drupflare_updates_screens(): array
{
	return ['update-core.php', 'update.php', 'plugin-install.php', 'theme-install.php'];
}

/**
 * A screen the menu hides from this user is refused before its own load hook runs, with a 403.
 */
function drupflare_updates_denied(): void
{
	global $pagenow;
	if (in_array((string) $pagenow, drupflare_updates_screens(), true)) {
		drupflare_updates_screen();
	}
}

/**
 * The update screens answer with the explanation as a page, not as a permissions error.
 */
function drupflare_updates_screen(): void
{
	wp_die(
		'<h1>' .
			esc_html('Updates are applied by the platform') .
			'</h1><p>' .
			esc_html(drupflare_updates_explanation()) .
			'</p>',
		'Updates',
		['response' => 200, 'link_url' => admin_url(), 'link_text' => 'Dashboard'],
	);
}

function drupflare_updates_notice(): void
{
	if (!current_user_can('manage_options')) {
		return;
	}
	$offer = drupflare_updates_offer();
	if ($offer === null) {
		return;
	}
	echo '<div class="notice notice-info"><p>' .
		esc_html(
			sprintf(
				'WordPress %s is available. The platform applies core updates; this dashboard does not.',
				$offer,
			),
		) .
		'</p></div>';
}

/**
 * Replaces the core version test, whose advice is a link to a screen that cannot update anything.
 *
 * @param array<string, array<string, array<string, mixed>>> $tests
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function drupflare_updates_site_status_tests($tests)
{
	if (!is_array($tests)) {
		return $tests;
	}
	unset($tests['direct']['wordpress_version'], $tests['async']['wordpress_version']);
	$tests['direct']['drupflare_core_updates'] = [
		'label' => 'Core updates are applied by the platform',
		'test' => static function (): array {
			$offer = drupflare_updates_offer();
			return [
				'label' =>
					$offer === null
						? 'WordPress core is current'
						: sprintf('WordPress %s is available', $offer),
				'status' => $offer === null ? 'good' : 'recommended',
				'badge' => ['label' => 'Security', 'color' => $offer === null ? 'blue' : 'orange'],
				'description' => '<p>' . esc_html(drupflare_updates_explanation()) . '</p>',
				'actions' => '',
				'test' => 'drupflare_core_updates',
			];
		},
	];
	return $tests;
}

/**
 * Removes core's own notice, which tells a user without the update capability to notify the
 * administrator. The admin filters that add it load after must-use plugins, so this runs at admin_init.
 */
function drupflare_updates_admin_init(): void
{
	remove_action('admin_notices', 'update_nag', 3);
	remove_action('network_admin_notices', 'update_nag', 3);
}

function drupflare_updates_register(): void
{
	add_action('admin_init', 'drupflare_updates_admin_init');
	add_action('admin_notices', 'drupflare_updates_notice', 3);
	foreach (drupflare_updates_screens() as $screen) {
		add_action('load-' . $screen, 'drupflare_updates_screen');
	}
	add_action('admin_page_access_denied', 'drupflare_updates_denied');
}
