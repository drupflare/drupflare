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

// first, so the neutral shim classes resolve for everything below and for the stand-ins
require_once __DIR__ . '/drupflare-host/shims.php';
require_once __DIR__ . '/drupflare-host/health.php';
require_once __DIR__ . '/drupflare-host/http.php';
require_once __DIR__ . '/drupflare-host/image.php';
require_once __DIR__ . '/drupflare-host/mail.php';
require_once __DIR__ . '/drupflare-host/multisite.php';
require_once __DIR__ . '/drupflare-host/status.php';
require_once __DIR__ . '/drupflare-host/updates.php';
require_once __DIR__ . '/drupflare-host/uploads.php';
require_once __DIR__ . '/drupflare-host/tags.php';

// at include, not plugins_loaded: options and queries read before then still count toward the tags
drupflare_tags_register();

// at include too: a request made by an early plugin must not reach a transport that is not here
add_filter('pre_http_request', 'drupflare_http_pre_request', 5, 3);
add_filter('wp_image_editors', 'drupflare_image_editors', 5);
add_filter('pre_wp_mail', 'drupflare_mail_pre_wp_mail', 5, 2);
drupflare_uploads_register();
drupflare_updates_register();
drupflare_multisite_register();
add_filter('upload_dir', 'drupflare_uploads_dir', 5);
add_filter('pre_move_uploaded_file', 'drupflare_uploads_pre_move', 5, 4);
add_filter('upload_size_limit', 'drupflare_uploads_size_limit', 20);

function drupflare_host_boot(): void
{
	add_filter('site_status_tests', 'drupflare_host_site_status_tests');
	add_filter('site_status_tests', 'drupflare_mail_site_status_tests');
	add_filter('site_status_tests', 'drupflare_updates_site_status_tests');
	add_filter('debug_information', 'drupflare_host_debug_information');
	add_action('rest_api_init', 'drupflare_host_register_routes');
	add_action('delete_transient_doing_cron', 'drupflare_host_record_cron');
}

add_action('plugins_loaded', 'drupflare_host_boot');
