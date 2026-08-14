<?php

/**
 * @file
 * Declaration-only stubs for the runtime's own functions, for STATIC ANALYSIS ONLY.
 *
 * Two families: `vrzno_*`, registered by the vrzno extension, and `pw_*`, the 32-bit bridge codec
 * the php-wasm build registers.
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
 * WHY IT EXISTS. `FetchHandler` calls `vrzno_env()` and `vrzno_await()` with no
 * `function_exists()` guard, and PHPStan reports `function.notFound` on a symbol it has never seen
 * declared. The alternative was disabling `undefinedFunctions` wholesale, which would hide every
 * genuinely misspelled function in `src/`. A stub costs one unreachable file.
 * `rom/stubs/vrzno.php` is the same file for the same reason.
 *
 * A GUARDED call needs no stub: PHPStan honours `function_exists()` and does not report a call
 * inside one. Verified by removing the guard at `Host.php:76`, which produces
 * `Function pw_encode not found` at level 5, and restoring it, which does not. The `pw_*` stubs
 * below are therefore not fixing a current error -- they keep the guard from being load-bearing,
 * so dropping one (reasonable, since the shipping binary always registers these) does not turn a
 * green analyse red for an unrelated change.
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

if (!\function_exists('pw_encode')) {
	/**
	 * Encodes a payload so values the JSON bridge cannot carry survive the crossing.
	 *
	 * `PHP_INT_SIZE` is 4 in the wasm build, so an integer above 2^31 wraps silently rather than
	 * erroring -- `Date.now()` came back as `-397708726`. This wraps wide values in an envelope
	 * the host decodes on the other side.
	 *
	 * @param mixed $value
	 *   The value to encode, usually the request array.
	 *
	 * @return mixed
	 *   The same shape with wide values enveloped.
	 */
	function pw_encode(mixed $value): mixed
	{
		// never executed: the php-wasm build owns this symbol at runtime
		throw new \LogicException('pw_encode stub called; the runtime is not loaded');
	}
}

if (!\function_exists('pw_decode')) {
	/**
	 * Reverses pw_encode() on a reply from the host.
	 *
	 * @param mixed $value
	 *   The decoded JSON reply.
	 *
	 * @return mixed
	 *   The same shape with enveloped values restored.
	 */
	function pw_decode(mixed $value): mixed
	{
		// never executed: the php-wasm build owns this symbol at runtime
		throw new \LogicException('pw_decode stub called; the runtime is not loaded');
	}
}
