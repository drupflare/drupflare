<?php

use Drupflare\Wp\BridgeWpdb;

/**
 * @return array<string, mixed>
 */
function drupflare_host_status(): array
{
	global $wpdb;

	$active = get_option('active_plugins', []);
	$lastCron = get_option('drupflare_host_last_cron', null);

	$status = [
		'wordpress_version' => (string) get_bloginfo('version'),
		'php_version' => PHP_VERSION,
		'db_class' => is_object($wpdb) ? get_class($wpdb) : null,
		'db_dropin_is_drupflare' => $wpdb instanceof BridgeWpdb,
		'db_server_version' => drupflare_host_declared_version(),
		'plugins_active' => is_array($active) ? count($active) : 0,
		'themes_installed' => count(wp_get_themes()),
		'last_cron' => is_numeric($lastCron) ? (int) $lastCron : null,
		'siteurl' => (string) get_option('siteurl'),
		'home' => (string) get_option('home'),
	];

	return apply_filters('drupflare_host_status', $status);
}

function drupflare_host_can_read_status(): bool
{
	return current_user_can('manage_options');
}

/**
 * @return array<string, mixed>|WP_Error|WP_REST_Response
 */
function drupflare_host_rest_status()
{
	if (!drupflare_host_can_read_status()) {
		$code = function_exists('rest_authorization_required_code')
			? rest_authorization_required_code()
			: 403;
		return new WP_Error(
			'drupflare_forbidden',
			'Sorry, you are not allowed to read the host status.',
			['status' => $code],
		);
	}

	$response = new WP_REST_Response(drupflare_host_status());
	$response->header('Cache-Control', 'no-store, max-age=0');
	return $response;
}

function drupflare_host_register_routes(): void
{
	register_rest_route('drupflare/v1', '/status', [
		'methods' => 'GET',
		'callback' => 'drupflare_host_rest_status',
		'permission_callback' => 'drupflare_host_can_read_status',
	]);
}

// core records no cron finish time; the lock transient is deleted when a run ends
function drupflare_host_record_cron(): void
{
	update_option('drupflare_host_last_cron', time(), false);
}
