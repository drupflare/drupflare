<?php

function drupflare_multisite_reason(): string
{
	return 'WordPress Multisite is not supported on drupflare. Each site has its own database and one pinned address, so one WordPress install cannot hold a network of sites. Create a separate site for each one.';
}

/**
 * Answers the Network Setup screen with the refusal.
 */
function drupflare_multisite_refuse(): void
{
	wp_die(
		'<h1>' .
			esc_html('Multisite is not supported') .
			'</h1><p>' .
			esc_html(drupflare_multisite_reason()) .
			'</p>',
		'Network Setup',
		['response' => 501, 'link_url' => admin_url(), 'link_text' => 'Dashboard'],
	);
}

/**
 * Whether a request path is one of the entry points only a network uses.
 */
function drupflare_multisite_entry(string $path): bool
{
	return preg_match('#/(wp-signup|wp-activate)\.php$#', $path) === 1 ||
		preg_match('#/wp-admin/network(/|$)#', $path) === 1;
}

function drupflare_multisite_register(): void
{
	add_action('load-network.php', 'drupflare_multisite_refuse');
	$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
	if (drupflare_multisite_entry($path)) {
		add_action('muplugins_loaded', 'drupflare_multisite_refuse');
	}
}
