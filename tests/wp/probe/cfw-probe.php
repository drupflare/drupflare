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
		// what the host plugin answers beyond the first two questions
		try {
			$refusals = [];
			foreach (
				['http://169.254.169.254/latest/meta-data/', 'http://localhost/']
				as $private
			) {
				$res = wp_remote_get($private, ['timeout' => 5]);
				$refusals[$private] = is_wp_error($res) ? $res->get_error_message() : 'SENT';
			}
			$out['refusals'] = $refusals;
			$post = wp_remote_post($url, ['timeout' => 10, 'body' => ['probe' => '1']]);
			$out['remotePost'] = is_wp_error($post)
				? ['error' => $post->get_error_message()]
				: ['status' => wp_remote_retrieve_response_code($post)];
			$self = wp_remote_get(home_url('/wp-json/'), ['timeout' => 10]);
			$out['selfFetch'] = is_wp_error($self)
				? ['error' => $self->get_error_message()]
				: ['status' => wp_remote_retrieve_response_code($self)];
			$out['outbound'] = function_exists('drupflare_http_state')
				? drupflare_http_state()
				: null;
		} catch (Throwable $e) {
			$out['remotePost'] = ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}
		try {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$png = sys_get_temp_dir() . '/cfw-probe.png';
			$rows = '';
			for ($y = 0; $y < 64; $y++) {
				$rows .= "\0" . str_repeat(chr($y * 3) . "\x20\x80\xff", 64);
			}
			$chunk = static fn(string $type, string $data) => pack('N', strlen($data)) .
				$type .
				$data .
				pack('N', crc32($type . $data));
			file_put_contents(
				$png,
				"\x89PNG\r\n\x1a\n" .
					$chunk('IHDR', pack('NNCCCCC', 64, 64, 8, 6, 0, 0, 0)) .
					$chunk('IDAT', gzcompress($rows)) .
					$chunk('IEND', ''),
			);
			$editor = wp_get_image_editor($png);
			if (is_wp_error($editor)) {
				$out['imageEditor'] = [
					'error' => $editor->get_error_code() . ': ' . $editor->get_error_message(),
				];
			} else {
				$resized = $editor->resize(32, 32);
				$saved =
					$resized === true
						? $editor->save(sys_get_temp_dir() . '/cfw-probe-32.png')
						: $resized;
				$size = is_wp_error($saved) ? null : @getimagesize($saved['path']);
				$out['imageEditor'] = [
					'class' => get_class($editor),
					'saved' => is_wp_error($saved)
						? $saved->get_error_message()
						: [$size[0] ?? null, $size[1] ?? null, $size['mime'] ?? null],
				];
			}
		} catch (Throwable $e) {
			$out['imageEditor'] = ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}
		echo 'CFWPROBE' . json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE) . 'CFWPROBE';
		throw new CfwWpStop('probe');
	},
	0,
);
