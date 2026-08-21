<?php

declare(strict_types=1);

namespace Drupal\drupflare\Plugin\Mail;

use Drupal;
use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupflare\Host;

/**
 * Hands mail to the Worker, which owns the transport.
 *
 * PHP-in-wasm has no sockets and no mail(), so every PHP mail transport is
 * unavailable -- not slow, absent. The Worker does have sockets, so what happens on
 * the other side of this call is a Cloudflare send or a real SMTP submission
 * depending on how the site is configured; see src/ops/mail.ts in the worker repo.
 * Drupal's mail system is a plugin, so the whole substitution is this class plus one
 * line of config (system.mail.interface.default), and no module needs changing.
 *
 * A TRUE RETURN MEANS ACCEPTED, NOT DELIVERED. The host commits the message and
 * sends it between PHP runs, because PHP calls the host synchronously and the
 * network needs an await. That is what an SMTP submission server means by 250, and
 * a failure after the hand-off is reported on the Worker's own diagnostics rather
 * than here.
 *
 * The format() half is unchanged from core's behaviour on purpose: converting HTML
 * to text and wrapping lines is Drupal's business, not the transport's.
 */
#[Mail(id: 'cfw_mail', label: new TranslatableMarkup('Cloudflare Email Service'))]
class CfwMail implements MailInterface
{
	use StringTranslationTrait;

	/**
	 * {@inheritdoc}
	 */
	public function format(array $message)
	{
		$message['body'] = implode(
			"\n\n",
			array_map(
				static fn($part) => (string) $part,
				is_array($message['body']) ? $message['body'] : [$message['body']],
			),
		);

		// an HTML body is converted rather than sent as-is, because the binding takes
		// text and html as separate fields and Drupal does not distinguish them here
		if (!empty($message['params']['html'])) {
			$message['html'] = $message['body'];
			$message['body'] = MailFormatHelper::htmlToText($message['body']);
		} else {
			$message['body'] = MailFormatHelper::wrapMail($message['body']);
		}

		return $message;
	}

	/**
	 * {@inheritdoc}
	 */
	public function mail(array $message)
	{
		if (!Host::has('cfwMail')) {
			// returning FALSE makes Drupal log a mail failure, which is the truthful
			// outcome; throwing would break whatever content operation triggered it
			Drupal::logger('drupflare')->error(
				'This site is not running inside a Worker, so mail to @to was not sent.',
				['@to' => $message['to'] ?? '(none)'],
			);
			return false;
		}

		$headers = is_array($message['headers'] ?? null) ? $message['headers'] : [];
		$reply = Host::call('cfwMail', [
			'to' => (string) ($message['to'] ?? ''),
			'from' => (string) ($headers['From'] ?? ($message['from'] ?? '')),
			'replyTo' => (string) ($headers['Reply-to'] ?? ($headers['Reply-To'] ?? '')),
			'subject' => (string) ($message['subject'] ?? ''),
			'text' => (string) ($message['body'] ?? ''),
			'html' => isset($message['html']) ? (string) $message['html'] : null,
			// the binding rejects unknown headers, so only the ones it names are passed
			'headers' => array_intersect_key(
				$headers,
				array_flip(['Cc', 'Bcc', 'In-Reply-To', 'References']),
			),
		]);

		if (($reply['ok'] ?? false) !== true) {
			Drupal::logger('drupflare')->error('Mail to @to failed: @error', [
				'@to' => $message['to'] ?? '(none)',
				'@error' => $reply['error'] ?? 'unknown',
			]);
			return false;
		}

		return true;
	}
}
