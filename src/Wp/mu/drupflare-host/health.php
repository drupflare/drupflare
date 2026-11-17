<?php

use Drupflare\Wp\Bridge;
use Drupflare\Wp\BridgeWpdb;

function drupflare_host_declared_version(): string
{
	global $wpdb;
	if (is_object($wpdb) && method_exists($wpdb, 'db_server_info')) {
		$version = $wpdb->db_server_info();
		if (is_string($version) && $version !== '') {
			return $version;
		}
	}
	return 'unknown';
}

/**
 * Tests that read false on this platform, keyed by Site Health test id.
 *
 * @return array<string, array{badge: string, label: string, description: string}>
 */
function drupflare_host_test_notes(): array
{
	$cache =
		'This test looks for a caching plugin or drop-in inside WordPress. Caching on drupflare is handled by the host, where the test cannot see it, so its result would not describe this site.';
	$updates =
		'On drupflare, updates to WordPress, plugins and themes are host operations. WordPress is not allowed to change its own files (DISALLOW_FILE_MODS is on), so this test does not apply.';
	$self =
		'This site cannot send requests to itself on drupflare, so Site Health cannot run this check from the server. Your browser reaches the site normally, and if wp-admin loads and saves, this part is working.';

	return [
		'persistent_object_cache' => [
			'badge' => 'Performance',
			'label' => 'The object cache is managed by the host',
			'description' => $cache,
		],
		'page_cache' => [
			'badge' => 'Performance',
			'label' => 'Page caching is managed by the host',
			'description' => $cache,
		],
		'php_sessions' => [
			'badge' => 'Security',
			'label' => 'PHP sessions are managed by the host',
			'description' =>
				'WordPress core does not use PHP sessions, and the host owns session handling on drupflare, so this check is skipped.',
		],
		'background_updates' => [
			'badge' => 'Security',
			'label' => 'Background updates are handled by the host',
			'description' => $updates,
		],
		'plugin_theme_auto_updates' => [
			'badge' => 'Security',
			'label' => 'Plugin and theme updates are handled by the host',
			'description' => $updates,
		],
		'update_temp_backup_writable' => [
			'badge' => 'Security',
			'label' => 'The update backup directory is not used',
			'description' => $updates,
		],
		'available_updates_disk_space' => [
			'badge' => 'Security',
			'label' => 'Update disk space is not used',
			'description' => $updates,
		],
		'scheduled_events' => [
			'badge' => 'Performance',
			'label' => 'Scheduled events are run by the platform',
			'description' =>
				'WordPress cron is driven by the platform rather than by visitors (DISABLE_WP_CRON is on), so the usual check of overdue events does not describe this site.',
		],
		'rest_availability' => [
			'badge' => 'Performance',
			'label' => 'The REST API cannot be tested from the server',
			'description' => $self,
		],
		'loopback_requests' => [
			'badge' => 'Performance',
			'label' => 'Loopback requests cannot be tested from the server',
			'description' => $self,
		],
		'sql_server' => [
			'badge' => 'Performance',
			'label' => 'The database is SQLite-backed behind a MySQL translator',
			'description' =>
				'The database on drupflare is SQLite-backed storage. WordPress reaches it through a translator that accepts MySQL statements and declares a version number. The usual check compares that number with MySQL release requirements, which does not describe this database, so it is replaced.',
		],
	];
}

/**
 * @param array{badge: string, label: string, description: string} $note
 *
 * @return array<string, mixed>
 */
function drupflare_host_test_result(string $key, array $note): array
{
	$description = '<p>' . esc_html($note['description']) . '</p>';
	if ($key === 'sql_server') {
		$description .=
			'<p>' .
			esc_html(sprintf('Declared server version: %s.', drupflare_host_declared_version())) .
			'</p>';
	}

	return [
		'label' => $note['label'],
		'status' => 'good',
		'badge' => ['label' => $note['badge'], 'color' => 'blue'],
		'description' => $description,
		'actions' => '',
		'test' => $key,
	];
}

/**
 * @param array<string, array<string, array<string, mixed>>> $tests
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function drupflare_host_site_status_tests($tests)
{
	if (!is_array($tests)) {
		return $tests;
	}

	foreach (drupflare_host_test_notes() as $key => $note) {
		if (!isset($tests['direct'][$key]) && !isset($tests['async'][$key])) {
			continue;
		}
		// an async test is fetched over a loopback rest call, so it moves to the direct list
		unset($tests['async'][$key]);
		$tests['direct'][$key] = [
			'label' => $note['label'],
			'test' => static fn(): array => drupflare_host_test_result($key, $note),
		];
	}

	return $tests;
}

function drupflare_host_constant_text(string $name): string
{
	return defined($name) ? var_export((bool) constant($name), true) : 'not set';
}

/**
 * @param array<string, mixed> $info
 *
 * @return array<string, mixed>
 */
function drupflare_host_debug_information($info)
{
	global $wpdb;
	if (!is_array($info)) {
		return $info;
	}

	$bridgeLoaded = class_exists(Bridge::class, false);
	$version = drupflare_host_declared_version();

	$info['drupflare'] = [
		'label' => 'Drupflare',
		'description' =>
			'How this site reaches its database and runs updates and cron on drupflare.',
		'fields' => [
			'host_function' => [
				'label' => 'Database bridge host function',
				'value' => $bridgeLoaded ? Bridge::HOST_FUNCTION : 'not loaded',
			],
			'host_function_installed' => [
				'label' => 'Database bridge host function installed',
				'value' => $bridgeLoaded ? ((new Bridge())->available() ? 'yes' : 'no') : 'unknown',
			],
			'server_version' => [
				'label' => 'Declared database server version',
				'value' => $version,
			],
			'wpdb_class' => [
				'label' => 'wpdb class',
				'value' => is_object($wpdb) ? get_class($wpdb) : 'not loaded',
			],
			'db_dropin_is_drupflare' => [
				'label' => 'Database drop-in is drupflare',
				'value' => $wpdb instanceof BridgeWpdb ? 'yes' : 'no',
			],
			'disallow_file_mods' => [
				'label' => 'DISALLOW_FILE_MODS',
				'value' => drupflare_host_constant_text('DISALLOW_FILE_MODS'),
			],
			'disable_wp_cron' => [
				'label' => 'DISABLE_WP_CRON',
				'value' => drupflare_host_constant_text('DISABLE_WP_CRON'),
			],
			'php_version' => [
				'label' => 'PHP version',
				'value' => PHP_VERSION,
			],
		],
	];

	if (isset($info['wp-database']['fields']['server_version'])) {
		$info['wp-database']['fields']['server_version']['value'] =
			$version . ' (SQLite-backed, behind a MySQL translator)';
	}

	return $info;
}
