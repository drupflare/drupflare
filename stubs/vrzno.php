<?php

/**
 * @file
 * Declaration-only stubs for the vrzno extension, for STATIC ANALYSIS ONLY.
 *
 * THIS FILE MUST NEVER BE LOADED AT RUNTIME. `vrzno` registers these symbols when the extension
 * initialises, so including this inside the wasm build is a hard `Cannot redeclare` fatal during
 * boot, before a byte is served. Three mechanisms keep it out and all three are load-bearing:
 *
 * 1. It is NOT in `composer.json` autoload -- the only PSR-4 root is `Drupal\drupflare\` -> `src/`,
 *    and there is no `files` or `classmap` entry.
 * 2. `.gitattributes` marks `stubs/` `export-ignore`, so it is absent from the Packagist archive
 *    Composer installs into `web/modules/contrib/drupflare`.
 * 3. `worker/scripts/gen-driver-assets.ts` skips any `stubs/` path when packing this module into
 *    `assets/driver.json`, which is the copy that executes on the edge.
 *
 * WHY IT EXISTS. `Host::fn()` guards with `function_exists('vrzno_env')`, but PHPStan reports
 * `function.notFound` on a symbol it has never seen declared anywhere, guard or no guard, and
 * `FetchHandler` calls both functions outside one. The alternative was disabling
 * `undefinedFunctions` wholesale, which would hide every genuinely misspelled function in `src/`.
 * A stub costs one unreachable file. `rom/stubs/vrzno.php` is the same file for the same reason.
 *
 * The return types are deliberately `mixed`: the real values are vrzno-wrapped JS objects, which
 * `is_callable()` may not recognise even when they are invocable. Declaring `callable` or `object`
 * would assert something the runtime does not guarantee.
 */

if (!\function_exists('vrzno_env')) {
	/**
	 * Resolves a name on the emscripten Module object, as surfaced by vrzno.
	 *
	 * `vrzno_env($name)` is `Module[$name]` -- the host functions the Durable Object installs on
	 * the module, including the mail, fetch, image and log bridges.
	 *
	 * @param string $name
	 *   The Module property to read.
	 *
	 * @return mixed
	 *   The wrapped JS value, or NULL when the name is not present.
	 */
	function vrzno_env(string $name): mixed
	{
		// never executed: the extension owns this symbol at runtime
		throw new \LogicException('vrzno stub called; the extension is not loaded');
	}
}

if (!\function_exists('vrzno_await')) {
	/**
	 * Suspends until a JS thenable settles, and returns what it settled with.
	 *
	 * Only usable on a build with a suspension mechanism. The shipping non-JSPI binary compiles
	 * `vrzno_await()` against Asyncify and raises `ReferenceError: Asyncify is not defined`, which
	 * is why `DrupflareServiceProvider` reads `cfwCanSuspend` before registering the handler that
	 * calls this.
	 *
	 * @param mixed $thenable
	 *   A vrzno-wrapped JS promise.
	 *
	 * @return mixed
	 *   The resolved value, wrapped the same way.
	 */
	function vrzno_await(mixed $thenable): mixed
	{
		// never executed: the extension owns this symbol at runtime
		throw new \LogicException('vrzno stub called; the extension is not loaded');
	}
}
