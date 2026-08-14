<?php

declare(strict_types=1);

namespace Drupal\drupflare\StreamWrapper;

use Closure;
use Drupal\drupflare\Host;
use Drupflare\StreamHttp\HttpsStreamWrapper as BaseHttpsStreamWrapper;

/**
 * Binds the generic https:// stream wrapper to this Worker's fetch capability.
 *
 * WHY THIS EXISTS AT ALL. Overriding Drupal's http_handler_stack fixes
 * Drupal::httpClient(), and nothing else. The measured wrapper list in this runtime is
 * compress.zlib, php, file, glob, data -- there is no http or https -- so any vendor or contrib
 * code calling file_get_contents('https://...') fails with "Unable to find the wrapper", and it
 * fails late, per call, rather than at container build. That is the worse failure mode for
 * diagnosis.
 *
 * WHY IT IS NOW A SUBCLASS OF A PACKAGE. The wrapper itself has nothing to do with Drupal: it is
 * PHP-in-wasm plumbing, and it shipped as drupflare/stream-http so other builds can use it. This
 * class had been a second, drifting copy of that code. All it adds -- and all it should add -- is
 * the ONE thing that is Drupal-specific: where the fetch comes from.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not stream. The whole body is fetched on open and
 * served from memory, because PHP's stream_read() is synchronous and a real streaming read would
 * need to suspend the interpreter mid-call, which requires JSPI. A build without suspension can
 * only fetch-then-read.
 *
 * Registered from TWO places, and both are needed. `drupflare.module` registers it at include
 * time, which is module-owned and runs every request: ModuleHandler::loadAll() is called from
 * DrupalKernel::preHandle() before anything routes, and no HOOK is that early -- a service
 * provider runs only on a container rebuild. The Worker host also registers it, earlier still,
 * from src/drupal/site-php.ts, which covers the window before a kernel exists at all.
 * DrupflareServiceProvider does not touch it.
 */
class HttpsStreamWrapper extends BaseHttpsStreamWrapper
{
	/**
	 * Registers this wrapper for http and https, defaulting to the Worker's fetch.
	 *
	 * The fetch is OPTIONAL here where the package requires it, which is the one thing this
	 * subclass is for: inside a Worker there is exactly one transport, so making five call sites
	 * name it would be ceremony around a fixed decision. `register()` with no arguments is the
	 * intended form and is what `drupflare.module` and the host both call.
	 *
	 * WIDENED RATHER THAN REPLACED, and PHP is what settled that. A zero-argument override is a
	 * fatal -- "Declaration ... must be compatible with ... register(callable $fetch, array
	 * $schemes)" -- raised at class load, so the module would have died before serving a byte.
	 * Making the parameter nullable with a default satisfies the parent (widening a parameter is
	 * allowed where narrowing is not) and keeps the escape hatch: pass a fetch to route one
	 * registration through another transport.
	 *
	 * @param callable|null $fetch
	 *   The transport; NULL means the Worker's `cfwFetch` capability.
	 * @param array $schemes
	 *   Which schemes to claim; both by default.
	 *
	 * @return array
	 *   The protocols actually registered.
	 */
	public static function register(?callable $fetch = null, array $schemes = self::SCHEMES): array
	{
		return parent::register($fetch ?? static::hostFetch(), $schemes);
	}

	/**
	 * The fetch closure, which is the whole of what this subclass contributes.
	 *
	 * The package's request array -- url, method, headers, body, redirect -- is already the shape
	 * `cfwFetch` takes, so this passes it through rather than translating it. If the two ever
	 * diverge, the translation belongs here and nowhere else.
	 *
	 * @return Closure
	 *   Takes the package's request array and returns the host's reply array.
	 */
	public static function hostFetch(): Closure
	{
		return static fn(array $request): mixed => Host::call('cfwFetch', $request);
	}
}
