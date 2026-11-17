<?php

/**
 * Plugin Name: Drupflare Platform Probe
 * Description: Answers two platform questions inside a deployed object; never part of the pack.
 * Version: 1.0.0
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit();
}

// the owner token puts this phase in the request; without it the file does nothing
if (($GLOBALS['cfwPhase'] ?? '') !== 'probe') {
	return;
}

add_action(
	'init',
	static function () {
		$url = (string) ($_GET['cfwurl'] ?? 'https://example.com/');
		$out = [
			'gdInfo' => null,
			'imagecreatefromstring' => function_exists('imagecreatefromstring'),
			'curlInit' => function_exists('curl_init'),
			'curlVersion' => null,
			'blockExternal' => defined('WP_HTTP_BLOCK_EXTERNAL') ? WP_HTTP_BLOCK_EXTERNAL : null,
			'remoteGet' => null,
			'curlExec' => null,
		];
		try {
			$gd = gd_info();
			$out['gdInfo'] = is_array($gd) ? $gd : $gd;
		} catch (Throwable $e) {
			$out['gdInfo'] = 'threw: ' . get_class($e) . ': ' . $e->getMessage();
		}
		try {
			$out['curlVersion'] = function_exists('curl_version') ? curl_version() : null;
		} catch (Throwable $e) {
			$out['curlVersion'] = 'threw: ' . $e->getMessage();
		}
		try {
			$res = wp_remote_get($url, ['timeout' => 10]);
			$out['remoteGet'] = is_wp_error($res)
				? ['error' => $res->get_error_code() . ': ' . $res->get_error_message()]
				: ['status' => wp_remote_retrieve_response_code($res)];
		} catch (Throwable $e) {
			$out['remoteGet'] = ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}
		// the block above is a wp-config.php setting, so the shim is asked directly as well
		try {
			if (function_exists('curl_init')) {
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_TIMEOUT, 10);
				$body = curl_exec($ch);
				$out['curlExec'] = [
					'ok' => $body !== false,
					'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
					'errno' => curl_errno($ch),
					'error' => curl_error($ch),
				];
			}
		} catch (Throwable $e) {
			$out['curlExec'] = ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}
		echo 'CFWPROBE' . json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE) . 'CFWPROBE';
		throw new CfwWpStop('probe');
	},
	0,
);
