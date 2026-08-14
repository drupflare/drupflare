<?php

declare(strict_types=1);

namespace Drupal\drupflare\Logger;

use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\drupflare\Host;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * Ships Drupal's log to the Worker runtime so an operator can see it.
 *
 * This does not replace dblog; it runs alongside it. Losing the database copy is
 * survivable, losing both is not.
 */
class CfwLogger implements LoggerInterface
{
	use RfcLoggerTrait;

	/**
	 * Maps RFC 5424 severities onto the four levels tail can filter on.
	 */
	private const LEVELS = [
		0 => 'error',
		1 => 'error',
		2 => 'error',
		3 => 'error',
		4 => 'warn',
		5 => 'log',
		6 => 'info',
		7 => 'debug',
	];

	public function __construct(private readonly LogMessageParserInterface $parser) {}

	/**
	 * {@inheritdoc}
	 */
	public function log($level, string|Stringable $message, array $context = []): void
	{
		$emit = Host::fn('cfwLog');
		if ($emit === null) {
			return;
		}

		// placeholders are resolved here rather than shipped raw, because a log line
		// an operator has to reassemble is not observability
		$variables = $this->parser->parseMessagePlaceholders($message, $context);
		$resolved = empty($variables) ? (string) $message : strtr((string) $message, $variables);

		$severity = is_int($level) ? $level : 6;
		$payload = [
			'level' => self::LEVELS[$severity] ?? 'log',
			'severity' => $severity,
			'channel' => (string) ($context['channel'] ?? 'php'),
			'message' => $resolved,
			'uid' => (int) ($context['uid'] ?? 0),
			'request_uri' => (string) ($context['request_uri'] ?? ''),
			'referer' => (string) ($context['referer'] ?? ''),
			'ip' => (string) ($context['ip'] ?? ''),
			// timestamps exceed 2^31, so they cross as a string rather than wrapping
			'timestamp' => (string) ($context['timestamp'] ?? time()),
		];
		if (!empty($context['exception']) && $context['exception'] instanceof Throwable) {
			$e = $context['exception'];
			$payload['exception'] = get_class($e) . ': ' . $e->getMessage();
			$payload['trace'] = substr($e->getTraceAsString(), 0, 2000);
		}

		$json = json_encode($payload);
		if (!is_string($json)) {
			return;
		}
		try {
			$emit($json);
		} catch (Throwable) {
			// a logger that throws turns a warning into an outage; swallow deliberately
		}
	}

	/**
	 * Installs a PHP error and exception handler that reaches the host.
	 *
	 * Drupal's own handlers route through the logger, which is enough for anything
	 * Drupal catches. This covers what it does not: a fatal during bootstrap, before
	 * the container exists, and a shutdown after an uncaught error -- both of which
	 * are currently silent.
	 */
	public static function installFatalHandler(): void
	{
		$emit = Host::fn('cfwLog');
		if ($emit === null) {
			return;
		}

		$ship = static function (array $payload) use ($emit): void {
			$json = json_encode($payload);
			if (is_string($json)) {
				try {
					$emit($json);
				} catch (Throwable) {
				}
			}
		};

		register_shutdown_function(static function () use ($ship): void {
			$error = error_get_last();
			// only the fatal classes; warnings already went through the logger
			if (
				$error !== null &&
				in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
			) {
				$ship([
					'level' => 'error',
					'channel' => 'php-fatal',
					'message' => $error['message'],
					'file' => $error['file'],
					'line' => $error['line'],
				]);
			}
		});
	}
}
