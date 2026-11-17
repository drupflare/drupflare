<?php

/**
 * Plugin Name: Drupflare Host
 * Description: Site Health and status for a site running on drupflare.
 * Version: 1.0.0
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit();
}

if (!defined('DISALLOW_FILE_MODS')) {
	define('DISALLOW_FILE_MODS', true);
}

if (!defined('DISABLE_WP_CRON')) {
	define('DISABLE_WP_CRON', true);
}

require_once __DIR__ . '/drupflare-host/health.php';
require_once __DIR__ . '/drupflare-host/status.php';
require_once __DIR__ . '/drupflare-host/tags.php';

// at include, not plugins_loaded: options and queries read before then still count toward the tags
drupflare_tags_register();

function drupflare_host_boot(): void
{
	add_filter('site_status_tests', 'drupflare_host_site_status_tests');
	add_filter('debug_information', 'drupflare_host_debug_information');
	add_action('rest_api_init', 'drupflare_host_register_routes');
	add_action('delete_transient_doing_cron', 'drupflare_host_record_cron');
	// updates are off (DISALLOW_FILE_MODS) and the checks cannot reach api.wordpress.org from here;
	// the version check also reads gd_info(), which the stand-in answers with false
	foreach (['wp_version_check', 'wp_update_plugins', 'wp_update_themes'] as $hook) {
		remove_action($hook, $hook);
	}
	// the admin also runs the same checks inline on every screen load, which would be the same fatal
	foreach (['_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes'] as $check) {
		remove_action('admin_init', $check);
	}
}

add_action('plugins_loaded', 'drupflare_host_boot');
