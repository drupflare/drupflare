<?php

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;

const DRUPFLARE_MAIL_DEFAULT_ATTACHMENT_MAX_BYTES = 700000;
const DRUPFLARE_MAIL_DEFAULT_ATTEMPTS = 3;

/** the raw bytes all attachments of one message may add up to; the queued row holds them as base64 */
function drupflare_mail_attachment_max_bytes(): int
{
	return defined('DRUPFLARE_MAIL_ATTACHMENT_MAX_BYTES')
		? max(0, (int) DRUPFLARE_MAIL_ATTACHMENT_MAX_BYTES)
		: DRUPFLARE_MAIL_DEFAULT_ATTACHMENT_MAX_BYTES;
}

/** how many sends one message may take; the host only repeats one the relay never took */
function drupflare_mail_attempts(): int
{
	return defined('DRUPFLARE_MAIL_ATTEMPTS')
		? max(1, (int) DRUPFLARE_MAIL_ATTEMPTS)
		: DRUPFLARE_MAIL_DEFAULT_ATTEMPTS;
}

/**
 * Splits one address list entry the way wp_mail does, into a name and an address.
 *
 * @return array{name: string, email: string}
 */
function drupflare_mail_split_address(string $address): array
{
	$name = '';
	$address = trim($address);
	if (preg_match('/(.*)<(.+)>/', $address, $matches) && count($matches) === 3) {
		$name = trim(str_replace('"', '', $matches[1]));
		$address = trim($matches[2]);
	}
	return ['name' => $name, 'email' => $address];
}

/** one header value with line breaks removed, so a name cannot start a new header */
function drupflare_mail_line(string $value): string
{
	return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
}

/** an address in the form the host takes, or null when it is not an address */
function drupflare_mail_format_address(string $name, string $email): ?string
{
	$email = drupflare_mail_line($email);
	if (!is_email($email)) {
		return null;
	}
	$name = drupflare_mail_line($name);
	// a name with a comma would split into two addresses on the host
	return $name === '' || str_contains($name, ',') ? $email : $name . ' <' . $email . '>';
}

/**
 * Reads a headers argument the way wp_mail does.
 *
 * @param string|array<int|string, string> $headers
 * @return array{from: string|null, from_name: string|null, content_type: string|null, cc: string[], bcc: string[], reply_to: string[], extra: array<string, string>}
 */
function drupflare_mail_parse_headers($headers): array
{
	$parsed = [
		'from' => null,
		'from_name' => null,
		'content_type' => null,
		'cc' => [],
		'bcc' => [],
		'reply_to' => [],
		'extra' => [],
	];
	if (empty($headers)) {
		return $parsed;
	}
	$lines = is_array($headers) ? $headers : explode("\n", str_replace("\r\n", "\n", $headers));
	foreach ($lines as $line) {
		$line = (string) $line;
		if (!str_contains($line, ':')) {
			continue;
		}
		[$name, $content] = explode(':', trim($line), 2);
		$name = trim($name);
		$content = trim($content);
		switch (strtolower($name)) {
			case 'from':
				$bracket = strpos($content, '<');
				if ($bracket !== false) {
					if ($bracket > 0) {
						$parsed['from_name'] = trim(
							str_replace('"', '', substr($content, 0, $bracket)),
						);
					}
					$parsed['from'] = trim(str_replace('>', '', substr($content, $bracket + 1)));
				} elseif ($content !== '') {
					$parsed['from'] = $content;
				}
				break;
			case 'content-type':
				if (str_contains($content, ';')) {
					$parsed['content_type'] = trim(explode(';', $content)[0]);
				} elseif ($content !== '') {
					$parsed['content_type'] = $content;
				}
				break;
			case 'cc':
				$parsed['cc'] = array_merge($parsed['cc'], explode(',', $content));
				break;
			case 'bcc':
				$parsed['bcc'] = array_merge($parsed['bcc'], explode(',', $content));
				break;
			case 'reply-to':
				$parsed['reply_to'] = array_merge($parsed['reply_to'], explode(',', $content));
				break;
			default:
				$parsed['extra'][$name] = $content;
		}
	}
	return $parsed;
}

/**
 * Reads attachment paths into the queue's shape, or says why they cannot go.
 *
 * @param array<int|string, string> $paths
 * @return array{files: array<int, array{filename: string, type: string, b64: string}>, error: string|null}
 */
function drupflare_mail_read_attachments(array $paths): array
{
	$files = [];
	$total = 0;
	$cap = drupflare_mail_attachment_max_bytes();
	foreach ($paths as $name => $path) {
		$path = (string) $path;
		if ($path === '') {
			continue;
		}
		if (!is_file($path) || !is_readable($path)) {
			return ['files' => [], 'error' => 'attachment is not readable: ' . basename($path)];
		}
		$size = (int) @filesize($path);
		$total += $size;
		if ($total > $cap) {
			return [
				'files' => [],
				'error' => sprintf(
					'attachments are %d bytes, over the %d-byte limit for one message',
					$total,
					$cap,
				),
			];
		}
		$bytes = file_get_contents($path);
		if ($bytes === false) {
			return ['files' => [], 'error' => 'attachment could not be read: ' . basename($path)];
		}
		$filename = is_string($name) && $name !== '' ? $name : basename($path);
		$type = wp_check_filetype(basename($path))['type'] ?: 'application/octet-stream';
		$files[] = [
			'filename' => drupflare_mail_line($filename),
			'type' => (string) $type,
			'b64' => base64_encode($bytes),
		];
	}
	return ['files' => $files, 'error' => null];
}

/** hands the failure to WordPress's own hook and answers false, which is what wp_mail returns */
function drupflare_mail_fail(string $message, array $data): bool
{
	do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $message, $data));
	return false;
}

/**
 * Answers wp_mail through the host instead of PHPMailer.
 *
 * True means the host accepted the message into its queue, as an SMTP server's 250 does. It says
 * nothing about delivery: WordPress has no delivered state to report.
 *
 * @param null|bool $pre
 * @param array<string, mixed> $atts
 */
function drupflare_mail_pre_wp_mail($pre, $atts)
{
	if ($pre !== null) {
		return $pre;
	}
	$atts = is_array($atts) ? $atts : [];
	$to = $atts['to'] ?? [];
	$to = is_array($to) ? $to : explode(',', (string) $to);
	$subject = (string) ($atts['subject'] ?? '');
	$message = (string) ($atts['message'] ?? '');
	$headers = $atts['headers'] ?? '';
	$attachments = $atts['attachments'] ?? [];
	$attachments = is_array($attachments)
		? $attachments
		: explode("\n", str_replace("\r\n", "\n", (string) $attachments));
	$embeds = $atts['embeds'] ?? [];
	$embeds = is_array($embeds)
		? $embeds
		: explode("\n", str_replace("\r\n", "\n", (string) $embeds));
	$data = compact('to', 'subject', 'message', 'headers', 'attachments');

	if (array_filter($embeds, static fn($e) => (string) $e !== '') !== []) {
		Degradation::record(
			'wp_mail embeds',
			'inline images are not sent, so a message that embeds a file is refused rather than sent without it',
		);
		return drupflare_mail_fail('inline images (embeds) are not supported on this host', $data);
	}
	if (!Host::has('cfwMail')) {
		return drupflare_mail_fail(
			'this site is not running inside a Worker, so the message was not sent',
			$data,
		);
	}

	$parsed = drupflare_mail_parse_headers($headers);
	if ($parsed['from'] === null) {
		$host = wp_parse_url(network_home_url(), PHP_URL_HOST);
		$parsed['from'] =
			'wordpress@' . (is_string($host) ? preg_replace('/^www\./', '', $host) : '');
	}
	$fromEmail = (string) apply_filters('wp_mail_from', $parsed['from']);
	$fromName = (string) apply_filters('wp_mail_from_name', $parsed['from_name'] ?? 'WordPress');
	$from = drupflare_mail_format_address($fromName, $fromEmail);
	if ($from === null) {
		return drupflare_mail_fail('Invalid address:  (From): ' . $fromEmail, $data);
	}

	$groups = [
		'to' => $to,
		'cc' => $parsed['cc'],
		'bcc' => $parsed['bcc'],
		'reply_to' => $parsed['reply_to'],
	];
	$addresses = ['to' => [], 'cc' => [], 'bcc' => [], 'reply_to' => []];
	foreach ($groups as $kind => $list) {
		foreach ((array) $list as $entry) {
			$split = drupflare_mail_split_address((string) $entry);
			$formatted = drupflare_mail_format_address($split['name'], $split['email']);
			// phpmailer skips an address it rejects and fails only when none is left
			if ($formatted !== null) {
				$addresses[$kind][] = $formatted;
			}
		}
	}
	if ($addresses['to'] === [] && $addresses['cc'] === [] && $addresses['bcc'] === []) {
		return drupflare_mail_fail('You must provide at least one recipient email address.', $data);
	}

	$contentType = (string) apply_filters(
		'wp_mail_content_type',
		$parsed['content_type'] ?? 'text/plain',
	);
	$isHtml = $contentType === 'text/html';
	if (str_starts_with($contentType, 'multipart/')) {
		Degradation::record(
			'wp_mail multipart',
			'a caller-built multipart body is not rebuilt, so it is sent as the plain text body it holds',
		);
	}

	$files = drupflare_mail_read_attachments($attachments);
	if ($files['error'] !== null) {
		return drupflare_mail_fail($files['error'], $data);
	}

	$extra = [];
	$threading = ['in-reply-to' => 'In-Reply-To', 'references' => 'References'];
	foreach ($parsed['extra'] as $name => $value) {
		if (isset($threading[strtolower($name)])) {
			$extra[$threading[strtolower($name)]] = drupflare_mail_line($value);
		} elseif (!in_array($name, ['MIME-Version', 'X-Mailer'], true)) {
			Degradation::record(
				'wp_mail headers',
				'only In-Reply-To and References pass through as custom headers, so any other header a caller sets is dropped',
			);
		}
	}
	foreach (['cc' => 'Cc', 'bcc' => 'Bcc'] as $kind => $name) {
		if ($addresses[$kind] !== []) {
			$extra[$name] = implode(', ', $addresses[$kind]);
		}
	}

	$reply = Host::call('cfwMail', [
		'to' => implode(', ', $addresses['to']),
		'from' => $from,
		'replyTo' => $addresses['reply_to'][0] ?? '',
		'subject' => drupflare_mail_line($subject),
		'text' => $isHtml ? wp_strip_all_tags($message) : $message,
		'html' => $isHtml ? $message : null,
		'headers' => $extra,
		'attachments' => $files['files'],
		'retry' => drupflare_mail_attempts(),
	]);
	if (($reply['ok'] ?? false) !== true) {
		return drupflare_mail_fail(
			'Mail was not accepted: ' . ($reply['error'] ?? 'unknown'),
			$data,
		);
	}
	do_action('wp_mail_succeeded', $data);
	return true;
}

/**
 * What the host knows about mail, read from the same snapshot the status page uses.
 *
 * @return array{transport: string|null, queued: int|null, failed: int, last_error: string|null, last_failed_at: int|null}|null
 *   null when this site is not running under the host
 */
function drupflare_mail_host_status(): ?array
{
	$invoke = Host::fn('cfwServeStats');
	if ($invoke === null) {
		return null;
	}
	$stats = json_decode((string) $invoke(), true);
	if (!is_array($stats)) {
		return null;
	}
	$failed = is_array($stats['mailFailed'] ?? null) ? $stats['mailFailed'] : [];
	return [
		'transport' => isset($stats['mailTransport']) ? (string) $stats['mailTransport'] : null,
		'queued' => isset($stats['mailQueue']) ? (int) $stats['mailQueue'] : null,
		'failed' => (int) ($failed['count'] ?? 0),
		'last_error' => isset($failed['lastError']) ? (string) $failed['lastError'] : null,
		'last_failed_at' => isset($failed['lastAt'])
			? (int) floor(((int) $failed['lastAt']) / 1000)
			: null,
	];
}

/**
 * @param array<string, array<string, array<string, mixed>>> $tests
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function drupflare_mail_site_status_tests($tests)
{
	if (is_array($tests)) {
		$tests['direct']['drupflare_mail'] = [
			'label' => 'Outgoing mail',
			'test' => 'drupflare_mail_site_health_test',
		];
	}
	return $tests;
}

/** one line for the debug screen */
function drupflare_mail_debug_value(): string
{
	$status = drupflare_mail_host_status();
	if ($status === null) {
		return 'not available in this deployment';
	}
	return sprintf(
		'transport %s; %s queued; %d failed after acceptance%s',
		$status['transport'] ?? 'none',
		$status['queued'] === null ? 'unknown' : (string) $status['queued'],
		$status['failed'],
		$status['last_error'] === null ? '' : '; last error: ' . $status['last_error'],
	);
}

/** the Site Health result for mail: a lost message is a recommendation, never a silent state */
function drupflare_mail_site_health_test(): array
{
	$status = drupflare_mail_host_status();
	$result = [
		'label' => 'Outgoing mail is accepted by the host',
		'status' => 'good',
		'badge' => ['label' => 'Mail', 'color' => 'blue'],
		'description' =>
			'<p>Mail goes to the host queue. A sent message means the host accepted it, not that it was delivered.</p>',
		'actions' => '',
		'test' => 'drupflare_mail',
	];
	if ($status === null) {
		return $result;
	}
	if ($status['transport'] === null) {
		$result['status'] = 'recommended';
		$result['label'] = 'No mail transport is configured';
		$result['description'] =
			'<p>The host has no mail transport, so every message is refused. Set a send_email binding, an Email Sending API token or an SMTP relay on the Worker.</p>';
		return $result;
	}
	if ($status['failed'] > 0) {
		$result['status'] = 'recommended';
		$result['label'] = sprintf('%d accepted messages could not be sent', $status['failed']);
		$result['description'] =
			'<p>' .
			esc_html(
				sprintf(
					'The host accepted %d messages and then failed to send them. The last error was: %s',
					$status['failed'],
					(string) $status['last_error'],
				),
			) .
			'</p>';
	}
	return $result;
}
