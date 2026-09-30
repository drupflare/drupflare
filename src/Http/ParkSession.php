<?php

declare(strict_types=1);

namespace Drupal\drupflare\Http;

use Drupal;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Throwable;

/**
 * Keeps the visitor's session across a park.
 *
 * A park leaves PHP's session status at NONE while `$_SESSION` keeps its contents, and no handler
 * close is called. Symfony still believes the session is started, so the request's final `save()`
 * finds no active session, starts one, and reads the stored row over `$_SESSION`: every message
 * and every other write made after the park is lost. Measured with the update module's cron fetch,
 * which made "Cron ran successfully." vanish after every Run cron.
 *
 * The session is saved before the park and started after it, which is what `DrupalKernel` does
 * around a container rebuild.
 */
final class ParkSession
{
	/**
	 * Returns the session service when a started one exists.
	 *
	 * @return SessionInterface|null
	 *   The started session, or NULL when there is none to save.
	 */
	public static function current(): ?SessionInterface
	{
		try {
			if (!class_exists(Drupal::class) || !Drupal::hasContainer()) {
				return null;
			}
			$container = Drupal::getContainer();
			if (!$container->initialized('session')) {
				return null;
			}
			$session = $container->get('session');
			return $session instanceof SessionInterface && $session->isStarted() ? $session : null;
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Runs the park with the session saved before it and started again after it.
	 *
	 * @param SessionInterface|null $session
	 *   The session from current(), or NULL to run the park alone.
	 * @param callable(): T $park
	 *   The call that parks.
	 *
	 * @return T
	 *   Whatever the park returned.
	 *
	 * @template T
	 */
	public static function around(?SessionInterface $session, callable $park): mixed
	{
		if ($session === null) {
			return $park();
		}
		$session->save();
		try {
			return $park();
		} finally {
			$session->start();
		}
	}
}
