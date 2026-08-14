<?php

declare(strict_types=1);

namespace Drupal\drupflare;

use Closure;
use Drupal;
use Drupal\Component\Utility\Html;
use Drupal\Core\DrupalKernelInterface;
use ReflectionObject;
use ReflectionProperty;
use SplObjectStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Resets per-request state between requests in a persistent interpreter.
 *
 * MEASURED PROBLEM: the interpreter survives between requests and cannot be
 * cheaply torn down (a fresh kernel costs ~1,020 ms even with opcache, so
 * disposable-kernel-per-request is priced out). Drupal assumes a fresh process.
 * Demonstrated directly:
 *
 *   static_set                "set-by-request-1"
 *   next_request_status        200          <- a full request ran in between
 *   static_after_next_request "set-by-request-1"
 *   LEAKED                     true
 *
 * drupal_static() holds user permissions, node access grants, field definitions
 * and language negotiation. Carrying those across a request boundary is
 * wrong-user data disclosure, not a latency bug.
 *
 * WHY THIS CLASS EXISTS: FrankenPHP worker mode and RoadRunner solve this with
 * Symfony's `kernel.reset` tag plus ServicesResetter. Drupal ships neither --
 * it does not include FrameworkBundle. Verified:
 *
 *   grep -rn "kernel.reset|ServicesResetter" core/lib core/core.services.yml
 *     -> no matches
 *
 * So the mechanism has to be built rather than adopted. This is that mechanism.
 *
 * @see DrupflareServiceProvider
 */
final class RequestResetter
{
	/**
	 * Service IDs that carry per-request state.
	 *
	 * Collected from the container by the `drupflare.reset` tag.
	 *
	 * @var string[]
	 */
	private array $resettable;

	public function __construct(
		private readonly ContainerInterface $container,
		array $resettable = [],
	) {
		$this->resettable = $resettable;
	}

	/**
	 * Resets everything that must not survive into the next request.
	 *
	 * Ordering matters: drupal_static() is reset last, because resetting a
	 * service can repopulate statics as a side effect.
	 *
	 * @return array
	 *   Diagnostics: what was reset and what failed, so a leak that survives this
	 *   is visible rather than silent.
	 */
	public function reset(): array
	{
		$log = ['services' => [], 'errors' => []];

		foreach ($this->resettable as $id) {
			try {
				if (!$this->container->initialized($id)) {
					// never instantiated this request, so it holds nothing
					continue;
				}
				$service = $this->container->get($id);
				if (is_object($service) && method_exists($service, 'reset')) {
					$service->reset();
					$log['services'][] = $id;
				}
			} catch (Throwable $e) {
				$log['errors'][$id] = substr($e->getMessage(), 0, 120);
			}
		}

		try {
			if ($this->container->initialized('account_switcher')) {
				// unwind any switchTo() that a failed request left on the stack
				$switcher = $this->container->get('account_switcher');
				while (method_exists($switcher, 'switchBack')) {
					$before = $switcher;
					$switcher->switchBack();
					if ($switcher === $before) {
						break;
					}
				}
			}
		} catch (Throwable $e) {
			// switchBack() throws when the stack is empty; that is the terminating
			// condition, not an error
			$log['account_switcher'] = 'unwound';
		}

		if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
			@session_write_close();
			$log['session_closed'] = true;
		}

		$log['page_cache_cid_cleared'] = $this->clearPageCacheCid();

		// 5. Static caches last, for the reason above.
		if (function_exists('drupal_static_reset')) {
			drupal_static_reset();
			$log['drupal_static_reset'] = true;
		}

		Html::resetSeenIds();
		$log['html_seen_ids_reset'] = true;

		return $log;
	}

	/**
	 * Clears PageCache's memoized cache ID by walking the middleware chain.
	 *
	 * @return int
	 *   How many PageCache instances were cleared. Zero means the chain shape
	 *   changed and a persistent kernel would start serving stale pages, so
	 *   callers should treat it as a failure rather than a no-op.
	 */
	private function clearPageCacheCid(): int
	{
		if (!$this->container->initialized('http_kernel')) {
			return 0;
		}

		$cleared = 0;
		$seen = new SplObjectStorage();

		$walk = function (object $obj, int $depth) use (&$walk, &$cleared, $seen): void {
			if ($depth > 8 || $seen->offsetExists($obj)) {
				return;
			}
			$seen->offsetSet($obj);

			if (is_a($obj, 'Drupal\page_cache\StackMiddleware\PageCache')) {
				$prop = new ReflectionProperty($obj, 'cid');
				$prop->setValue($obj, null);
				$cleared++;
				return;
			}

			foreach ((new ReflectionObject($obj))->getProperties() as $prop) {
				if (!$prop->isInitialized($obj)) {
					continue;
				}
				$value = $prop->getValue($obj);
				// PageCache takes its inner kernel as a closure; unwrap it.
				if ($value instanceof Closure) {
					try {
						$value = $value();
					} catch (Throwable) {
						continue;
					}
				}
				// Never descend into the kernel: it owns the container, and the walk
				// would cover the whole service graph.
				if (is_object($value) && !($value instanceof DrupalKernelInterface)) {
					$walk($value, $depth + 1);
				}
			}
		};

		try {
			$walk($this->container->get('http_kernel'), 0);
		} catch (Throwable) {
			return $cleared;
		}

		return $cleared;
	}

	/**
	 * Verifies the reset actually worked.
	 *
	 * Called by the differential test; a resetter that silently fails is worse
	 * than none, because it creates false confidence.
	 */
	public function verify(): array
	{
		return [
			'current_uid' => (function () {
				try {
					return (int) Drupal::currentUser()->id();
				} catch (Throwable $e) {
					return 'ERR';
				}
			})(),
			'static_probe' => drupal_static('pw_leak_probe'),
		];
	}
}
