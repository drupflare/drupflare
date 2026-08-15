<?php

declare(strict_types=1);

namespace Drupal\drupflare;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\drupflare\Cache\CfwCacheBackendFactory;
use Drupal\drupflare\Http\FetchHandler;
use Drupal\Core\Cache\DatabaseBackendFactory;
use Drupal\Core\Routing\MatcherDumper;
use Drupal\drupflare\Routing\CfwMatcherDumper;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Points Drupal's HTTP client at the Worker runtime instead of curl/streams.
 *
 * Drupal registers the Guzzle transport as a swappable service, which is what
 * makes this a two-line change rather than a fork:
 *
 *   http_handler_stack:
 *     class: GuzzleHttp\HandlerStack
 *     factory: GuzzleHttp\HandlerStack::create
 *   http_client_factory:
 *     arguments: ['@http_handler_stack']
 *
 * HandlerStack::create($handler) accepts the transport as its first argument,
 * so overriding the factory arguments replaces the transport for every consumer
 * of Drupal::httpClient() with no module changes.
 *
 * This is the reference case for the capability pattern: PHP asks for
 * something, and the runtime satisfies it with a platform primitive instead of
 * a compiled C library.
 */
final class DrupflareServiceProvider implements ServiceProviderInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function register(ContainerBuilder $container): void
	{
		$this->registerRouterDumper($container);
		$this->registerCacheBackend($container);

		if (!$container->hasDefinition('http_handler_stack')) {
			return;
		}

		// GUARDED, and it was not before: FetchHandler's own docblock says "Do not
		// enable it on an ASYNCIFY=0, non-JSPI runtime", and this method installed
		// it unconditionally. On the shipping binary that is a guaranteed
		// "ReferenceError: Asyncify is not defined" the first time anything calls
		// Drupal::httpClient().
		//
		// Not swapped to CfwDeferredHttp as the fallback either: returning a 202
		// instead of a body is a real behaviour change, so a site opts into that per
		// service (see drupflare.services.yml). Leaving core's handler in place
		// means outbound HTTP goes through the https stream wrapper the host
		// registers, which is the behaviour that actually works today.
		if (!self::runtimeCanSuspend()) {
			$this->registerResetter($container);
			return;
		}

		$handler = new Definition(FetchHandler::class);
		$handler->setPublic(false);
		$container->setDefinition('drupflare.fetch_handler', $handler);

		// HandlerStack::create() takes the transport as its only argument. Leaving
		// the configurator in place preserves Drupal's http_client_middleware tags.
		$container
			->getDefinition('http_handler_stack')
			->setArguments([$container->getDefinition('drupflare.fetch_handler')]);

		$this->registerResetter($container);
	}

	/**
	 * Points `router.dumper` at the fingerprinting subclass.
	 *
	 * A router rebuild is 419 routes against three indexes -- 2,095 charged rows -- written whether
	 * or not the collection changed, and rows written is the meter that binds regeneration.
	 * `ModuleInstaller::doInstall()` rebuilds the container once per module, which resets
	 * `RouteProviderLazyBuilder::$rebuilt` and re-arms the trigger, so an install with dependencies
	 * dumps the same routes repeatedly.
	 *
	 * REGISTERED HERE for the reason the collapse cannot live anywhere else: a service provider runs
	 * on every container build, so this survives exactly the rebuild that discards a decorated
	 * `router.builder` or any counter held in the container.
	 *
	 * Core's own arguments and `backend_overridable` tag are preserved, so a site that overrode the
	 * dumper itself keeps its override.
	 */
	private function registerRouterDumper(ContainerBuilder $container): void
	{
		if (!$container->hasDefinition('router.dumper')) {
			return;
		}
		$definition = $container->getDefinition('router.dumper');
		if ($definition->getClass() !== MatcherDumper::class) {
			return;
		}
		$definition->setClass(CfwMatcherDumper::class);
		$definition->setLazy(false);
	}

	/**
	 * Points `cache.backend.database` at the factory that drops the unread bin indexes.
	 *
	 * Every cache bin carries an `expire` and a `created` index on top of its `cid` primary key, and
	 * on Durable Object billing each one is another charged row per insert. Measured in workerd: 10
	 * rows cost 40 charged rows with both and 20 without, exactly 2.00x. The only reader is the
	 * host's own GC, which caps `cache_data` alone -- so 13 of the 14 bins pay for indexes nothing
	 * queries.
	 *
	 * A factory subclass rather than a class swap on the backend, because core's factory constructs
	 * `DatabaseBackend` directly instead of resolving a class name.
	 */
	private function registerCacheBackend(ContainerBuilder $container): void
	{
		if (!$container->hasDefinition('cache.backend.database')) {
			return;
		}
		$definition = $container->getDefinition('cache.backend.database');
		if ($definition->getClass() !== DatabaseBackendFactory::class) {
			return;
		}
		$definition->setClass(CfwCacheBackendFactory::class);
	}

	/**
	 * Whether the interpreter can suspend, which is what FetchHandler requires.
	 *
	 * `vrzno_await()` is compiled against Asyncify. The shipping build sets
	 * ASYNCIFY=0 to save 42% of the bundle, so the symbol is simply absent; a JSPI
	 * build provides the same semantics without the bloat.
	 *
	 * Probed rather than configured, because a settings flag would drift from the
	 * binary that is actually loaded, and the binary is what decides.
	 */
	private static function runtimeCanSuspend(): bool
	{
		// set by the host only on a build whose suspension mechanism is present
		if (function_exists('vrzno_env')) {
			$flag = vrzno_env('cfwCanSuspend');
			if (is_bool($flag)) {
				return $flag;
			}
		}
		return false;
	}

	/**
	 * Registers the per-request resetter.
	 *
	 * Drupal ships no `kernel.reset` tag and no ServicesResetter, so the tag and
	 * the collector are both defined here. Any service holding per-request state
	 * gets tagged `drupflare.reset` and implements `reset()`.
	 *
	 * The seed list below is the services whose state is known to be per-request
	 * and per-user. It is deliberately explicit rather than "everything with a
	 * reset() method", because resetting the wrong service mid-boot is its own
	 * failure mode.
	 */
	private function registerResetter(ContainerBuilder $container): void
	{
		$seed = [
			// identity, which is the disclosure case
			'current_user',
			'account_switcher',
			'session',
			'request_stack',
			// access results are cached per user
			'entity_type.manager',
			'entity.memory_cache',
			'cache.static',
			'access_manager',
			// per-request render and language state
			'renderer',
			'language_manager',
			'path.current',
			'theme.manager',
			// node grants, which are the classic Drupal leak
			'node.grant_storage',
		];

		$resettable = [];
		foreach ($seed as $id) {
			if ($container->has($id)) {
				$resettable[] = $id;
			}
		}

		// anything a module tagged explicitly
		foreach (array_keys($container->findTaggedServiceIds('drupflare.reset')) as $id) {
			if (!in_array($id, $resettable, true)) {
				$resettable[] = $id;
			}
		}

		$resetter = new Definition(RequestResetter::class, [
			new Reference('service_container'),
			$resettable,
		]);
		$resetter->setPublic(true);
		$container->setDefinition('drupflare.request_resetter', $resetter);
	}
}
