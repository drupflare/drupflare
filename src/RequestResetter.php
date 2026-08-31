<?php

declare(strict_types=1);

namespace Drupal\drupflare;

use Closure;
use Drupal;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheCollector;
use Drupal\Core\Database\Database;
use Drupal\Core\DestructableInterface;
use Drupal\Core\DrupalKernelInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\views\Plugin\views\display\Page as ViewsPage;
use ReflectionObject;
use ReflectionProperty;
use SplObjectStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Resets per-request state between requests in a persistent interpreter.
 *
 * Measured: the interpreter survives between requests and cannot be
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
 * FrankenPHP worker mode and RoadRunner solve this with
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
	 * How many `switchBack()` calls the unwind will make before giving up.
	 *
	 * A bound rather than an unconditional loop: the terminating condition is a throw from the
	 * switcher, and a backend that never throws would spin here forever.
	 */
	public const MAX_SWITCHER_DEPTH = 64;

	/**
	 * How many output-buffer levels the unwind will close before giving up.
	 *
	 * A bound for the same reason as the one above: `ob_end_clean()` refuses on a buffer the
	 * runtime marked unremovable, and an unconditional loop would spin on it.
	 */
	public const MAX_OUTPUT_BUFFER_DEPTH = 64;

	/**
	 * Services whose memoised cache key reads per-request context and is then never recomputed.
	 *
	 * Each value is the cid shape that makes the entry necessary; read `getCid()` before adding one.
	 * A collector with a fixed cid, or one keyed by an argument rather than by ambient state, does
	 * not belong here -- resetting it costs a rebuild and buys nothing.
	 *
	 * The first two are `CacheCollector` services and are reset as such. The third is not: it is a
	 * translator that HOLDS a `LocaleLookup` per langcode and context, and its `reset()` drops those
	 * objects outright, which is what clears their memoised cids. It is also the only one of the
	 * three whose key carries the USER rather than the route, so it is the one that survives a
	 * request the other two would not.
	 *
	 * @var array<string, string>
	 */
	private const REQUEST_SCOPED_COLLECTORS = [
		'library.discovery' => 'library_info:<active theme>',
		'menu.active_trail' => 'active-trail:route:<route name>',
		'string_translator.locale.lookup' => 'locale:<langcode>:<context>:<the user\'s role ids>',
	];

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
		$log = ['services' => [], 'skipped' => [], 'errors' => []];

		// an unclosed buffer is a PHP PROCESS global, like the header list, and it survives a
		// script boundary here: measured, two ob_start() calls left open were still open in the
		// next fragment, which would swallow the whole of the next response
		$log['output_buffers_closed'] = $this->closeOutputBuffers();

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
					continue;
				}
				// RECORDED, not passed over. Ten of the thirteen seeded ids have no reset() and
				// were skipped in silence, which is how theme.manager sat on the list for months
				// looking handled while pinning one visitor's theme onto every later request.
				$log['skipped'][] = $id;
			} catch (Throwable $e) {
				$log['errors'][$id] = substr($e->getMessage(), 0, 120);
			}
		}

		try {
			if ($this->container->initialized('account_switcher')) {
				// unwind any switchTo() that a failed request left on the stack
				$switcher = $this->container->get('account_switcher');
				if (method_exists($switcher, 'switchBack')) {
					// ONE switchBack() is not an unwind, and the previous loop only ever made
					// one: it compared `$switcher` to a copy of itself, which is always the
					// same object, so it broke on the first pass and left a depth-2 stack at
					// depth 1. That is the state `account.not_restored` fires on.
					for ($depth = 0; $depth < self::MAX_SWITCHER_DEPTH; $depth++) {
						$switcher->switchBack();
					}
					// only reachable when switchBack() never refuses, which no core backend
					// does; recorded rather than swallowed so a stuck stack is visible
					$log['account_switcher'] = 'bound reached';
				}
			}
		} catch (Throwable $e) {
			// switchBack() throws when the stack is empty; that is the terminating
			// condition, not an error
			$log['account_switcher'] = 'unwound';
		}

		$log['identity'] = $this->resetIdentity();
		$log['session'] = $this->resetSession();
		$log['theme'] = $this->resetActiveTheme();
		$log['collectors'] = $this->resetRequestScopedCollectors();
		$log['transaction'] = $this->discardOrphanedTransactions();

		$log['page_cache_cid_cleared'] = $this->clearPageCacheCid();

		// 5. Static caches last, for the reason above.
		if (function_exists('drupal_static_reset')) {
			drupal_static_reset();
			$log['drupal_static_reset'] = true;
		}

		Html::resetSeenIds();
		$log['html_seen_ids_reset'] = true;

		$log['form_errors_reset'] = $this->clearFormErrors();
		$log['render_contexts_dropped'] = $this->clearRenderContexts();
		$log['views_page_render_array_cleared'] = $this->clearViewsPageRenderArray();

		return $log;
	}

	/**
	 * Closes every output buffer the previous request left open.
	 *
	 * PHP's buffer stack belongs to the process, and this interpreter is the process. Measured:
	 * two `ob_start()` calls left open by one fragment were still open at the start of the next, so
	 * a handler that forgets its `ob_end_clean()` does not cost one response, it costs every
	 * response after it -- the output goes into a buffer nobody will close and the host reads
	 * nothing at all.
	 *
	 * Discarded rather than flushed: whatever is in there belongs to a request that has ended, and
	 * emitting it would prepend one visitor's partial page to the next visitor's.
	 *
	 * @return int
	 *   How many levels were closed; zero on a clean boundary, which is every measured render.
	 */
	private function closeOutputBuffers(): int
	{
		$closed = 0;
		while (ob_get_level() > 0 && $closed < self::MAX_OUTPUT_BUFFER_DEPTH) {
			if (!@ob_end_clean()) {
				// an unremovable buffer, which nothing here creates; stop rather than spin
				break;
			}
			$closed++;
		}
		return $closed;
	}

	/**
	 * Returns the active theme to unnegotiated, because Drupal will not do it per request.
	 *
	 * `ThemeManager::getActiveTheme()` memoises into `$activeTheme` and the only reset in core is
	 * `resetActiveTheme()`, called from `user_login`/`user_logout` and from the theme settings form
	 * -- never on an ordinary request, because on a real SAPI the process dies instead. So the
	 * FIRST route an object negotiates decides the theme for every request after it.
	 *
	 * Measured on a warm object, both directions and both wrong:
	 *
	 *   admin GET /admin/content (claro), then anonymous GET /  -> 14,933 bytes against 17,670 cold
	 *   admin GET / (olivero), then admin GET /admin/content    -> 110,173 bytes against 118,931
	 *
	 * The first is a visitor being served admin-theme markup; the second is the admin interface
	 * rendered in the front-end theme. `theme.manager` was already on the seed list, which is
	 * exactly why this went unnoticed: it has no `reset()`, so the loop above skipped it in
	 * silence and the id read as handled.
	 *
	 * @return string|bool
	 *   The theme that was cleared, TRUE when there was none, FALSE when the service is absent.
	 */
	private function resetActiveTheme(): string|bool
	{
		try {
			if (!$this->container->initialized('theme.manager')) {
				return false;
			}
			$manager = $this->container->get('theme.manager');
			if (!method_exists($manager, 'resetActiveTheme')) {
				return false;
			}
			$previous =
				method_exists($manager, 'hasActiveTheme') && $manager->hasActiveTheme()
					? (string) $manager->getActiveTheme()->getName()
					: '';
			$manager->resetActiveTheme();
			return $previous === '' ? true : $previous;
		} catch (Throwable $e) {
			// the reset log this used to write into has no reader anywhere, so a failed step was
			// recorded and then discarded. The consequence is in this method's own docblock: a
			// theme pinned by one request renders every request after it
			Degradation::record(
				'request reset theme',
				'the active theme could not be reset between requests, so a theme negotiated for one visitor can render for the next: ' .
					$e->getMessage(),
			);
			return false;
		}
	}

	/**
	 * Returns the real service behind a generated lazy proxy, or the object itself.
	 *
	 * A service marked `lazy: true` is handed out as a `Drupal\Core\ProxyClass\...` wrapper that
	 * forwards method calls and holds the real object in `$service`. Method calls therefore work
	 * and reflection does not, which is a reset that reports success and changes nothing.
	 *
	 * @param object $service
	 *   The service the container returned.
	 *
	 * @return object
	 *   The object reflection should be pointed at.
	 */
	private function unwrapProxy(object $service): object
	{
		try {
			$reflection = new ReflectionObject($service);
			if (!$reflection->hasProperty('drupalProxyOriginalServiceId')) {
				return $service;
			}
			if (method_exists($service, 'lazyLoadItself')) {
				// forces instantiation, which has already happened by the time a reset runs
				$method = $reflection->getMethod('lazyLoadItself');
				$loaded = $method->invoke($service);
				if (is_object($loaded)) {
					return $loaded;
				}
			}
			if ($reflection->hasProperty('service')) {
				$inner = $reflection->getProperty('service');
				if ($inner->isInitialized($service) && is_object($inner->getValue($service))) {
					return $inner->getValue($service);
				}
			}
		} catch (Throwable) {
			return $service;
		}
		return $service;
	}

	/**
	 * Drops the caches whose key is the request they were first asked in.
	 *
	 * A `CacheCollector` computes `getCid()` once and memoises it, then holds the resolved values in
	 * `$storage`. That is correct in a process that serves one request. Here the cid is computed
	 * from the FIRST request the object serves and every later one is answered against it, so a
	 * collector keyed on the active theme or on the route is a leak by construction. `getCid()` is
	 * the tell, and it is what to read before adding an id here.
	 *
	 * Measured, both after the `theme.manager` reset was already in place:
	 *
	 *   admin route then anonymous /   -> 17,869 bytes against 17,670 cold, carrying two Claro
	 *                                     stylesheets on an Olivero page
	 *   /user/login then anonymous /   -> 17,596 bytes, 74 characters of real content short: the
	 *                                     Home item lost its active-trail and is-active classes
	 *
	 * Emptying `dynamic_page_cache`, `render` and `page` moved neither, which is what ruled out a
	 * cached render array and named the collectors.
	 *
	 * `string_translator.locale.lookup` is the third id and it is a different shape twice over. It
	 * is a `LocaleTranslation`, not a collector: it holds one `LocaleLookup` per langcode and
	 * context in `$translations`, and `reset()` drops that array, which is what discards their
	 * memoised cids -- there is no cid on the service itself, so this one reports `reset-only`.
	 * And `LocaleLookup::getCid()` folds in `Drupal::currentUser()->getRoles()`, so unlike the two
	 * above it carries the USER across the boundary rather than the route. Measured with `locale`
	 * enabled, an admin `/admin/content` render followed by an anonymous `/`:
	 *
	 *   en|            locale:en::anonymous
	 *   en|HTML tag    locale:en:HTML tag:administrator:authenticated
	 *
	 * and the second one did not move for the anonymous request or for the ordinary user after it.
	 *
	 * DESTRUCTED FIRST, and that is not tidiness. `CacheCollector::reset()` discards `keysToPersist`
	 * along with the storage, so resetting alone would throw away what the request just resolved and
	 * make the next one rebuild it. `destruct()` writes it out under the cid it was resolved for, so
	 * the next request pays one cache read. Both ids are among the five services measured as
	 * destructing safely here; `theme.registry` is the one that is not, and it is absent -- its
	 * runtime registry is keyed by theme name, so it does not have this defect anyway.
	 *
	 * @return array
	 *   What was cleared per id, so a half-applied reset is visible rather than assumed.
	 */
	private function resetRequestScopedCollectors(): array
	{
		$out = [];
		foreach (self::REQUEST_SCOPED_COLLECTORS as $id => $_reason) {
			try {
				if (!$this->container->initialized($id)) {
					continue;
				}
				$collector = $this->container->get($id);
				if (!is_object($collector) || !method_exists($collector, 'reset')) {
					$out[$id] = 'no-reset';
					continue;
				}
				if ($collector instanceof DestructableInterface) {
					$collector->destruct();
				}
				$collector->reset();
				// AND THE MEMOISED CID, which reset() does not touch. CacheCollector::reset()
				// clears $storage and leaves $cid, so the next request loads the PREVIOUS route's
				// entry straight back out of the cache -- and the destruct() above has just written
				// it there, so emptying the cache bin does not help either. LibraryDiscoveryCollector
				// overrides reset() to null it and MenuActiveTrail does not, which is the whole
				// difference between the two.
				//
				// UNWRAPPED FIRST. menu.active_trail is `lazy: true`, so the container hands back a
				// generated ProxyClass; the forwarded reset() worked and the reflection did not,
				// which looked exactly like a reset that had no effect.
				$real = $this->unwrapProxy($collector);
				if ($real instanceof CacheCollector) {
					$property = new ReflectionProperty(CacheCollector::class, 'cid');
					$property->setValue($real, null);
					$out[$id] = true;
					continue;
				}
				$out[$id] = 'reset-only';
			} catch (Throwable $e) {
				$out[$id] = substr($e->getMessage(), 0, 120);
			}
		}

		// LibraryDependencyResolver is not a collector and is worse: it memoises the dependency
		// closure of each library into $librariesDependencies keyed by LIBRARY NAME with no theme in
		// the key, and it has no reset(), no clearCachedDefinitions() and no needs_destruction tag.
		// Resetting the discovery collector alone left one Claro stylesheet on the Olivero page,
		// which is how this one was found.
		try {
			if ($this->container->initialized('library.dependency_resolver')) {
				$resolver = $this->container->get('library.dependency_resolver');
				$property = new ReflectionProperty($resolver, 'librariesDependencies');
				$property->setValue($resolver, []);
				$out['library.dependency_resolver'] = true;
			}
		} catch (Throwable $e) {
			$out['library.dependency_resolver'] = substr($e->getMessage(), 0, 120);
		}

		return $out;
	}

	/**
	 * Empties the static render-context collection, which nothing else ever shrinks.
	 *
	 * `Renderer::$contextCollection` is a `protected static SplObjectStorage` keyed by the Request
	 * OBJECT, so it is correct per request and unbounded across them: every request ever served
	 * stays referenced, with its cookies, its POST body and its session. Measured over one warm
	 * sequence it went 0, 1, 2, 5, 6 and never down, against 1 in a fresh object serving the same
	 * page. Against a 115 MB heap ceiling that is the shape of an eviction, not of a leak that
	 * discloses -- but the requests it pins are one visitor's and the object serves everyone.
	 *
	 * Replaced rather than emptied: `Renderer::__construct()` only creates one when the property is
	 * unset, so handing it a fresh SplObjectStorage is what the constructor would have done.
	 *
	 * `views\Plugin\views\display\Page::$pageRenderArray` is cleared by
	 * {@see self::clearViewsPageRenderArray()} rather than here, because it needs no reflection.
	 *
	 * @return int
	 *   How many request contexts were dropped.
	 */
	private function clearRenderContexts(): int
	{
		try {
			$property = new ReflectionProperty(Renderer::class, 'contextCollection');
			$value = $property->isInitialized() ? $property->getValue() : null;
			$dropped = $value instanceof SplObjectStorage ? $value->count() : 0;
			$property->setValue(null, new SplObjectStorage());
			return $dropped;
		} catch (Throwable) {
			return -1;
		}
	}

	/**
	 * Empties the views page render array, which is held on a class static BY REFERENCE.
	 *
	 * `ViewPageController` calls `Page::setPageRenderArray($element)` and core never clears it, so
	 * one render array stays pinned for the life of the interpreter. It is overwritten by the next
	 * views page rather than accumulating, and Drupal 11 core has no reader -- but the assignment is
	 * BY REFERENCE, so a contrib caller reading the getter on a request that did not set one is
	 * handed the previous visitor's array. "Benign" was a claim about the shipped module set, not
	 * about the mechanism, and the module set is a moving target.
	 *
	 * NO REFLECTION, which is why this is now done at all. The previous note here said clearing it
	 * needed a reflection call on a class name phpstan cannot resolve to a `class-string`; that was
	 * wrong. `setPageRenderArray()` is public static, and it assigns whenever the argument is set --
	 * so passing an empty array clears it through core's own API. It takes the argument by
	 * reference, hence the local.
	 *
	 * `class_exists(..., false)` does NOT autoload. If views has not been loaded in
	 * this interpreter then nothing set the static and there is nothing to clear; autoloading it
	 * here would be this method creating the class it came to reset.
	 *
	 * @return bool
	 *   Whether the static was cleared. FALSE means views was never loaded.
	 */
	private function clearViewsPageRenderArray(): bool
	{
		if (!class_exists(ViewsPage::class, false)) {
			return false;
		}
		try {
			$empty = [];
			ViewsPage::setPageRenderArray($empty);
			return true;
		} catch (Throwable $e) {
			Degradation::record(
				'request reset views',
				'a Views page render array survived into the next request, so one visitor can be served a view built for another: ' .
					$e->getMessage(),
			);
			return false;
		}
	}

	/**
	 * Rolls back any transaction a halted request left open, on every connection already made.
	 *
	 * The driver buffers writes and replays them at commit, and the buffer lives on a Connection
	 * that `Database::$connections` holds for the life of the interpreter. Measured: after a script
	 * ended between `startTransaction()` and its commit, the next render answered 200 with the
	 * usual byte count, `isBuffering()` was still TRUE afterwards, and the row it wrote was not in
	 * the database. Every request after the halt writes into a buffer nobody will replay.
	 *
	 * Only connections that are ALREADY OPEN are touched, read off `Database::$connections` rather
	 * than through `Database::getConnection()`, which would open one and create the state this is
	 * supposed to be clearing.
	 *
	 * @return array
	 *   What was discarded per connection key, or the reason nothing was.
	 */
	private function discardOrphanedTransactions(): array
	{
		try {
			$property = new ReflectionProperty(Database::class, 'connections');
			$open = $property->isInitialized() ? (array) $property->getValue() : [];
		} catch (Throwable $e) {
			return ['error' => substr($e->getMessage(), 0, 120)];
		}

		$out = [];
		foreach ($open as $key => $targets) {
			foreach ((array) $targets as $target => $connection) {
				if (
					!is_object($connection) ||
					!method_exists($connection, 'discardOrphanedTransaction')
				) {
					continue;
				}
				try {
					$discarded = $connection->discardOrphanedTransaction();
					// a clean boundary discards nothing, and reporting that would make every
					// render's log carry a row that means "no news"
					if (($discarded['buffered'] ?? 0) > 0 || ($discarded['stack'] ?? 0) > 0) {
						$out[$key . ':' . $target] = $discarded;
					}
				} catch (Throwable $e) {
					$out[$key . ':' . $target] = ['error' => substr($e->getMessage(), 0, 120)];
				}
			}
		}
		return $out;
	}

	/**
	 * Clears `FormState::$anyErrors`, which is a CLASS STATIC that gates every submit handler.
	 *
	 * One mistyped password used to disable login for the whole object.
	 * `FormState::setErrorByName()` sets `static::$anyErrors = true`, and
	 * `FormBuilder::processForm()` runs submit handlers only `if (!$form_state->isRebuilding() &&
	 * !FormState::hasAnyErrors())`. The only reset in core is `FormState::clearErrors()`, called
	 * from `FormBuilder::submitForm()` -- the PROGRAMMATIC path -- so a normal HTTP request never
	 * clears it. On a real SAPI that is fine because the process dies; here it does not.
	 *
	 * The result was a form that validated, authenticated, and then silently did nothing: the login
	 * form came back rebuilt with no message, no session and uid 0, indistinguishable from a page
	 * that was never submitted. A rejected CSRF token reached the same state by the same route.
	 *
	 * `clearErrors()` is an INSTANCE method that happens to touch a static, so there is no form
	 * state to hand it here -- reflection on the static property is the only way to reach it from
	 * outside a request. Failing quietly is correct: a runtime that renamed the property should
	 * lose this reset, not refuse to serve.
	 */
	private function clearFormErrors(): bool
	{
		try {
			$property = new ReflectionProperty(FormState::class, 'anyErrors');
			$property->setValue(null, false);
			return true;
		} catch (Throwable $e) {
			Degradation::record(
				'request reset form errors',
				'the static form-error flag could not be cleared, so a form that failed validation for one visitor reports errors for the next: ' .
					$e->getMessage(),
			);
			return false;
		}
	}

	/**
	 * Returns the current user to anonymous, because Drupal will not do it.
	 *
	 * Drupal never resets the account to anonymous, and that is not an oversight -- in a fresh
	 * process `AccountProxy` starts anonymous, so there is nothing to undo.
	 * `AuthenticationSubscriber::onKernelRequestAuthenticate()` sets an account only when a
	 * provider APPLIES and RETURNS one, and returns silently otherwise
	 * (`core/lib/Drupal/Core/EventSubscriber/AuthenticationSubscriber.php:106`). On a persistent
	 * interpreter "otherwise" means the previous visitor stays signed in.
	 *
	 * Measured: after one admin login, a request carrying NO cookie came back
	 * "This route can only be accessed by anonymous users" from `/user/login`, with
	 * `\Drupal::currentUser()->id()` still 1. That is the wrong-user disclosure this class was
	 * written for, reached through the one service that has no `reset()` to call.
	 *
	 * @return array
	 *   The uid before and after, so a reset that did not take is visible rather than assumed.
	 */
	private function resetIdentity(): array
	{
		$out = [];
		try {
			if (!$this->container->initialized('current_user')) {
				return ['skipped' => 'not initialized'];
			}
			$proxy = $this->container->get('current_user');
			$out['before'] = (int) $proxy->id();
			if (method_exists($proxy, 'setAccount')) {
				$proxy->setAccount(new AnonymousUserSession());
			}
			// setInitialAccountId() throws once an account is set, and the kernel calls it on the
			// next boot-from-session path; clearing it keeps that route open
			$reflection = new ReflectionObject($proxy);
			if ($reflection->hasProperty('id')) {
				$reflection->getProperty('id')->setValue($proxy, 0);
			}
			$out['after'] = (int) $proxy->id();
		} catch (Throwable $e) {
			$out['error'] = substr($e->getMessage(), 0, 120);
			// the highest-severity one in this class: an unrestored currentUser is the uid-1
			// poisoning, where admin HTML was stored in the anonymous page cache
			Degradation::record(
				'request reset identity',
				'the acting user could not be returned to anonymous between requests, so one visitor can be rendered as another: ' .
					$e->getMessage(),
			);
		}
		return $out;
	}

	/**
	 * Ends the current visitor's session so the next one cannot inherit it.
	 *
	 * Writing comes first and is not optional: the visitor's session belongs in the `sessions`
	 * table. What must not survive is the copy in memory.
	 *
	 * @return array
	 *   What was closed and which flags were cleared, so a leak that survives this is visible.
	 */
	private function resetSession(): array
	{
		if (!function_exists('session_status')) {
			return ['ext' => false];
		}

		$out = ['ext' => true, 'closed' => false, 'storage' => []];
		if (session_status() === PHP_SESSION_ACTIVE) {
			@session_write_close();
			$out['closed'] = true;
		}
		$_SESSION = [];

		foreach (['session', 'session_manager'] as $id) {
			try {
				if (!$this->container->initialized($id)) {
					continue;
				}
				$service = $this->container->get($id);
				if (!is_object($service)) {
					continue;
				}
				$reflection = new ReflectionObject($service);
				foreach (['started', 'closed', 'startedLazy'] as $name) {
					if (!$reflection->hasProperty($name)) {
						continue;
					}
					$reflection->getProperty($name)->setValue($service, false);
					$out['storage'][] = $id . '.' . $name;
				}
				$out['bags'] = array_merge(
					$out['bags'] ?? [],
					$this->rebindBags($service, $reflection),
				);
			} catch (Throwable $e) {
				$out['errors'][$id] = substr($e->getMessage(), 0, 120);
			}
		}

		return $out;
	}

	/**
	 * Points the session bags at the emptied superglobal, which clearing it does not do.
	 *
	 * `NativeSessionStorage::loadSession()` binds each bag BY REFERENCE -- `$bag->initialize(
	 * $session[$key])` -- so `$_SESSION = []` above rebinds the global and leaves every bag holding
	 * the previous visitor's array. Drupal only calls `start()` when there is a session to start,
	 * and it does not start one for an anonymous visitor with no cookie, so nothing rebinds them.
	 *
	 * The flash bag is where that becomes a disclosure. Measured: uid 1 saved a node, the
	 * "has been created" status message went into the flash bag, and the NEXT request -- anonymous,
	 * no cookie, a plain GET of the front page -- rendered that message and drained the bag. On
	 * this runtime the anonymous front page is also what the fill alarm stores, so one visitor's
	 * message can be baked into the page every other visitor is served.
	 *
	 * Rebinding rather than deleting keeps the case the flash bag exists for: an authenticated
	 * visitor's own redirect target restores `$_SESSION` from the `sessions` table and Drupal calls
	 * `start()`, which binds the bags again to the restored data, so that visitor still sees the
	 * message they earned.
	 *
	 * @param object $service
	 *   The session service.
	 * @param ReflectionObject $reflection
	 *   Its reflection, already built by the caller.
	 *
	 * @return string[]
	 *   The storage keys that were rebound.
	 */
	private function rebindBags(object $service, ReflectionObject $reflection): array
	{
		$bags = [];
		foreach (['bags', 'metadataBag'] as $name) {
			if (!$reflection->hasProperty($name)) {
				continue;
			}
			$property = $reflection->getProperty($name);
			if (!$property->isInitialized($service)) {
				continue;
			}
			$value = $property->getValue($service);
			foreach (is_array($value) ? $value : [$value] as $bag) {
				if (
					is_object($bag) &&
					method_exists($bag, 'initialize') &&
					method_exists($bag, 'getStorageKey')
				) {
					$bags[] = $bag;
				}
			}
		}

		$rebound = [];
		foreach ($bags as $bag) {
			$key = (string) $bag->getStorageKey();
			$_SESSION[$key] = [];
			$bag->initialize($_SESSION[$key]);
			$rebound[] = $key;
		}
		return $rebound;
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
