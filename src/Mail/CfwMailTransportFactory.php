<?php

declare(strict_types=1);

namespace Drupal\drupflare\Mail;

use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds {@see CfwMailTransport} for a `cfwmail://` DSN.
 *
 * Tagged `mailer.transport_factory`, which symfony_mailer collects into its transport factory
 * manager. Without that module the tag is inert.
 */
final class CfwMailTransportFactory extends AbstractTransportFactory
{
	/**
	 * {@inheritdoc}
	 */
	public function create(Dsn $dsn): TransportInterface
	{
		return new CfwMailTransport($this->dispatcher, $this->logger);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function getSupportedSchemes(): array
	{
		return ['cfwmail'];
	}
}
