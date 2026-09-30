<?php

declare(strict_types=1);

namespace Drupal\drupflare;

use Drupal\Core\Site\Settings;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * The end-of-request work a persistent interpreter never does by itself.
 *
 * PHP's shutdown functions and `KernelEvents::TERMINATE` belong to a process that is about to exit,
 * and this interpreter is reused, so neither runs. The host calls {@see self::drain()} after a
 * successful render to run the two pieces that work here:
 *
 * - the callbacks queued with `drupal_register_shutdown_function()`, which media derivative
 *   reactions and similar modules rely on, run in order and the queue is emptied so the next
 *   render does not repeat them;
 * - TERMINATE subscribers named in the `drupflare_terminate_subscribers` setting, by class name.
 *
 * A full `$kernel->terminate()` is not an option: automated_cron subscribes to TERMINATE and runs
 * cron inline, and terminating a kernel this interpreter keeps left every later render empty. So
 * the subscribers are opt-in, and automated_cron is refused by name whatever the setting says.
 */
final class Terminate
{
	/**
	 * TERMINATE subscribers that are refused even when named.
	 *
	 * @var array<string, string>
	 */
	public const REFUSED = [
		'Drupal\automated_cron\EventSubscriber\AutomatedCron' =>
			'it runs cron inline, which reaches for outbound HTTP inside the render',
	];

	/**
	 * Runs the shutdown queue and the named TERMINATE subscribers.
	 *
	 * @param HttpKernelInterface $kernel
	 *   The kernel handed to subscribers.
	 * @param Request $request
	 *   The request that was handled.
	 * @param Response $response
	 *   The response it produced; a server error skips everything.
	 * @param EventDispatcherInterface|null $dispatcher
	 *   The dispatcher to read listeners from; null uses the event_dispatcher service.
	 * @param string[]|null $subscribers
	 *   Class names to run; null reads the `drupflare_terminate_subscribers` setting.
	 *
	 * @return array{skipped: string|null, shutdown: array{ran: int, failed: string[]}, terminate: array{ran: string[], refused: string[]}}
	 *   What ran and what did not.
	 */
	public static function drain(
		HttpKernelInterface $kernel,
		Request $request,
		Response $response,
		?EventDispatcherInterface $dispatcher = null,
		?array $subscribers = null,
	): array {
		$report = [
			'skipped' => null,
			'shutdown' => ['ran' => 0, 'failed' => []],
			'terminate' => ['ran' => [], 'refused' => []],
		];
		if ($response->getStatusCode() >= 500) {
			$report['skipped'] = 'the response is a server error';
			return $report;
		}
		$report['shutdown'] = self::shutdown();
		$named = $subscribers ?? (array) Settings::get('drupflare_terminate_subscribers', []);
		if ($named !== []) {
			$dispatcher ??= \Drupal::service('event_dispatcher');
			$report['terminate'] = self::runSubscribers(
				$dispatcher,
				$kernel,
				$request,
				$response,
				$named,
			);
		}
		return $report;
	}

	/**
	 * Runs and empties the `drupal_register_shutdown_function()` queue.
	 *
	 * A callback may queue another, so the queue is read until it is empty. A callback that throws
	 * is reported and does not stop the rest.
	 *
	 * @return array{ran: int, failed: string[]}
	 *   How many callbacks ran and the messages of those that threw.
	 */
	private static function shutdown(): array
	{
		$queue = &drupal_register_shutdown_function();
		$ran = 0;
		$failed = [];
		while ($queue !== []) {
			$entry = array_shift($queue);
			try {
				call_user_func_array($entry['callback'], $entry['arguments']);
				$ran++;
			} catch (Throwable $e) {
				$failed[] = get_class($e) . ': ' . $e->getMessage();
			}
		}
		return ['ran' => $ran, 'failed' => $failed];
	}

	/**
	 * The reason a subscriber is refused, or null.
	 *
	 * @param string $class
	 *   The subscriber class name.
	 *
	 * @return string|null
	 *   The reason, or null when the subscriber may run.
	 */
	private static function refusal(string $class): ?string
	{
		$reasons = self::REFUSED;
		return $reasons[$class] ?? null;
	}

	/**
	 * Runs the named TERMINATE subscribers.
	 *
	 * @param EventDispatcherInterface $dispatcher
	 *   The dispatcher to read listeners from.
	 * @param HttpKernelInterface $kernel
	 *   The kernel handed to subscribers.
	 * @param Request $request
	 *   The request that was handled.
	 * @param Response $response
	 *   The response it produced.
	 * @param string[] $named
	 *   Class names allowed to run.
	 *
	 * @return array{ran: string[], refused: string[]}
	 *   The subscribers that ran and those refused by name.
	 */
	private static function runSubscribers(
		EventDispatcherInterface $dispatcher,
		HttpKernelInterface $kernel,
		Request $request,
		Response $response,
		array $named,
	): array {
		$ran = [];
		$refused = [];
		foreach ($dispatcher->getListeners(KernelEvents::TERMINATE) as $listener) {
			$owner =
				is_array($listener) && is_object($listener[0]) ? get_class($listener[0]) : null;
			if ($owner === null || !in_array($owner, $named, true)) {
				continue;
			}
			$why = self::refusal($owner);
			if ($why !== null) {
				$refused[] = $owner;
				Degradation::record('terminate ' . $owner, $why, 'blocked');
				continue;
			}
			try {
				$listener(
					new TerminateEvent($kernel, $request, $response),
					KernelEvents::TERMINATE,
					$dispatcher,
				);
				$ran[] = $owner;
			} catch (Throwable $e) {
				Degradation::record(
					'terminate ' . $owner,
					get_class($e) . ': ' . $e->getMessage(),
					'untested',
				);
			}
		}
		return ['ran' => $ran, 'refused' => $refused];
	}
}
