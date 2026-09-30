<?php

declare(strict_types=1);

namespace Drupal\drupflare\Mail;

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;
use Drupal\drupflare\Plugin\Mail\CfwMail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A Symfony Mailer transport that hands the message to the host, the way {@see CfwMail} does.
 *
 * The symfony_mailer module opens its own SMTP socket, which PHP in wasm cannot. Setting a transport's DSN to
 * `cfwmail://default` sends through the same host queue instead, so the module runs unmodified.
 * Success means the host accepted the message, as it does for the Drupal mail plugin.
 *
 * Attachments and inline images are refused: the host binding takes text and html only, and
 * sending the message without them would report success for a different email.
 */
final class CfwMailTransport extends AbstractTransport
{
	/**
	 * {@inheritdoc}
	 */
	public function __toString(): string
	{
		return 'cfwmail://default';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function doSend(SentMessage $message): void
	{
		$email = $message->getOriginalMessage();
		if (!($email instanceof Email)) {
			throw new TransportException('cfwmail sends Email messages only.');
		}
		if ($email->getAttachments() !== []) {
			Degradation::record(
				'mailer attachments',
				'the host mail binding takes text and html only, so a message with attachments or inline images is refused',
			);
			throw new TransportException('cfwmail cannot send attachments or inline images.');
		}
		if (!Host::has('cfwMail')) {
			throw new TransportException(
				'This site is not running inside a Worker, so the message was not sent.',
			);
		}

		$headers = [];
		foreach (['Cc' => $email->getCc(), 'Bcc' => $email->getBcc()] as $name => $addresses) {
			if ($addresses !== []) {
				$headers[$name] = self::list($addresses);
			}
		}
		foreach (['In-Reply-To', 'References'] as $name) {
			$header = $email->getHeaders()->get($name);
			if ($header !== null) {
				$headers[$name] = $header->getBodyAsString();
			}
		}
		$from = $email->getFrom();
		$replyTo = $email->getReplyTo();
		$html = $email->getHtmlBody();
		$text = $email->getTextBody();
		$reply = Host::call('cfwMail', [
			'to' => self::list($email->getTo()),
			'from' => $from === [] ? '' : $from[0]->toString(),
			'replyTo' => $replyTo === [] ? '' : $replyTo[0]->toString(),
			'subject' => (string) $email->getSubject(),
			'text' => is_resource($text) ? (string) stream_get_contents($text) : (string) $text,
			'html' =>
				$html === null
					? null
					: (is_resource($html)
						? (string) stream_get_contents($html)
						: $html),
			'headers' => $headers,
			'smtp' => CfwMail::smtpSettings(),
		]);
		if (($reply['ok'] ?? false) !== true) {
			throw new TransportException(
				'Mail was not accepted: ' . ($reply['error'] ?? 'unknown'),
			);
		}
	}

	/**
	 * Joins addresses into one header value.
	 *
	 * @param Address[] $addresses
	 *   The addresses.
	 *
	 * @return string
	 *   The addresses, comma separated.
	 */
	private static function list(array $addresses): string
	{
		return implode(
			', ',
			array_map(static fn(Address $a): string => $a->toString(), $addresses),
		);
	}
}
