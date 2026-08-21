# 🔌 drupflare

> Drupal 11 module that satisfies platform needs with Cloudflare Workers bindings

[![Build](https://github.com/drupflare/drupflare/actions/workflows/build.yml/badge.svg)](https://github.com/drupflare/drupflare/actions/workflows/build.yml)
[![Prettier](https://github.com/drupflare/drupflare/actions/workflows/prettier.yml/badge.svg)](https://github.com/drupflare/drupflare/actions/workflows/prettier.yml)
[![Packagist](https://img.shields.io/packagist/v/drupflare/drupflare)](https://packagist.org/packages/drupflare/drupflare)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

drupflare replaces the compiled C libraries Drupal expects with Cloudflare Workers primitives. Mail,
outbound HTTP, images and logging become bindings, and a persistent PHP interpreter gets a
per-request reset. One seam, `Host`, carries every call to the runtime; the rest are Drupal plugins
that go through it.

---

## 📋 Table of Contents

- [Why Bindings](#-why-bindings)
- [What It Provides](#-what-it-provides)
- [Host Contract](#-host-contract)
- [Installation](#-installation)
- [Configuration](#-configuration)
- [Testing](#-testing)
- [Platform Limits](#-platform-limits)
- [Related Repositories](#-related-repositories)
- [Contributing](#-contributing)
- [License](#-license)

---

## 🎯 Why Bindings

A Worker has no sockets, no `gd`, no SMTP transport and no writable disk worth using. PHP compiled to
WebAssembly arrives missing what Drupal assumes, and compiling those libraries in is expensive: `gd`
alone costs **684,821 bytes** of a bundle that must fit Cloudflare's 3 MB free ceiling.

The platform already provides all of it. Cloudflare has an email binding, `fetch()`, Cloudflare
Images and Workers Logs. Drupal's mail system, image toolkit, HTTP transport and logger are
**swappable plugins**, so reaching those bindings takes a module rather than a fork.

---

## 🧩 What It Provides

| Class                              | Replaces                      | Host function                                      |
| ---------------------------------- | ----------------------------- | -------------------------------------------------- |
| `Host`                             | -                             | the seam itself; every other class goes through it |
| `Plugin\Mail\CfwMail`              | `mail()` / SMTP               | `cfwMail`                                          |
| `ImageToolkit\CfwImageToolkit`     | `gd`                          | `cfwImageUrl`                                      |
| `Logger\CfwLogger`                 | `dblog` as the only sink      | `cfwLog`                                           |
| `StreamWrapper\HttpsStreamWrapper` | the absent `https://` wrapper | `cfwFetch`                                         |
| `Queue\CfwDeferredHttp`            | a blocking HTTP client        | `cfwHttpCacheGet`, `cfwQueueFetch`, `cfwFetchSync` |
| `Http\FetchHandler`                | curl / the stream handler     | `cfHost` (**needs JSPI, not exercised**)           |
| `DrupflareServiceProvider`         | -                             | swaps `http_handler_stack`, builds the resetter    |
| `RequestResetter`                  | a fresh PHP process           | -                                                  |
| `drupflare.module`                 | a host-side registration call | registers `http`/`https` on every request          |
| `Hook\Requirements`                | -                             | probes all of them for the status report           |
| `Install\Requirements\Drupflare…`  | -                             | the same probe at install time, never blocking     |

Three of these carry behaviour that differs from what they replace.

**`CfwImageToolkit` never processes an image.** It records the dimensions a manipulation _would_ have
produced so Drupal emits correct `width`/`height` attributes, and reports every operation as
successful-but-deferred so image styles do not fail. Resizing happens at delivery, from a Cloudflare
Images URL. A style-derived file on disk is therefore the original, so contrib that reads a
derivative's own pixels sees full-size images. Drupal core does not.

**`CfwDeferredHttp` answers 202 instead of a body.** Ten core files touch the HTTP client and none
needs a synchronous response on the anonymous request path: six are cron or admin, four are oEmbed,
which is cacheable after first resolution. The layering is cached -> deferred -> sync, and only the
last tier needs a suspension mechanism. A caller that cannot proceed without a body gets a 202 and
must handle it, so it is selected per service rather than globally.

**The interpreter survives between requests, and Drupal assumes a fresh process.** A fresh kernel
costs ~1,020 ms even with opcache, which prices out a disposable kernel per request. A
`drupal_static()` value set by one request is still present in the next. `drupal_static()` holds user
permissions, node access grants and language negotiation, so carrying it across a request boundary
is wrong-user data disclosure rather than a latency bug. Symfony solves this with `kernel.reset` plus
`ServicesResetter`; **Drupal ships neither**, because it does not include FrameworkBundle.

`RequestResetter` also closes two failures specific to a persistent kernel. PHP's session module
stays open, so every request after the first throws `Failed to start the session: already started by
PHP`. `PageCache` memoizes `$this->cid` on a middleware instance that never goes away, which makes
`/user/login`, `/node/1` and `/admin/content` re-serve the first request's page.

---

## 🔗 Host Contract

`Host` is the only class that calls `vrzno_env()`. It centralises two things that would otherwise be
repeated in every plugin.

**The bridge is 32-bit.** `PHP_INT_SIZE` is 4 in the wasm build, so anything above 2^31 wraps
silently rather than erroring; `Date.now()` comes back as `-397708726`. Wide values cross as a codec
envelope or a string.

**A capability may be absent.** A Worker deployed without an email binding has no `cfwMail`, so "is
this available" has one answer, in one place.

```php
use Drupal\drupflare\Host;

if (Host::has('cfwMail')) {
  $reply = Host::call('cfwMail', ['to' => 'someone@example.com', 'subject' => 'Hi']);
  // $reply always has an 'ok' key; on failure it also has 'error'
}
```

The eight names the module asks the runtime for:

| Name              | Direction | Needed for                                          |
| ----------------- | --------- | --------------------------------------------------- |
| `cfwLog`          | sync      | `CfwLogger`, and the fatal handler                  |
| `cfwMail`         | sync      | `CfwMail`                                           |
| `cfwImageUrl`     | sync      | `CfwImageToolkit::deliveryUrl()`                    |
| `cfwFetch`        | sync      | `HttpsStreamWrapper::stream_open()`                 |
| `cfwHttpCacheGet` | sync      | `CfwDeferredHttp`, the cached tier                  |
| `cfwQueueFetch`   | sync      | `CfwDeferredHttp`, the deferred tier                |
| `cfwFetchSync`    | sync      | `CfwDeferredHttp`, the sync tier (**absent today**) |
| `cfHost`          | async     | `FetchHandler` (**needs JSPI**)                     |

Every call is synchronous. `Host::call()` does `$reply = $invoke($json)` and reads the result
immediately, because PHP on an `ASYNCIFY=0` build cannot await. The `fetch()` itself happens in
JavaScript between PHP runs and lands in a durable cache table the next PHP run reads, so outbound
HTTP works cached-or-deferred without suspension.

---

## 📦 Installation

```sh
composer require drupflare/drupflare
drush en drupflare
```

`composer.json` sets `extra.installer-name`, so `composer/installers` places the module at
`web/modules/contrib/drupflare` under its machine name, which is what Drupal discovers it by.

The module is only useful **inside** a Worker. Outside one, `vrzno_env()` does not exist, every
`Host::has()` returns `FALSE`, and every capability declines by name.

---

## 🔧 Configuration

Mail and images are plugins, so each takes one line:

```sh
drush config:set system.mail interface.default cfw_mail
drush config:set system.image toolkit cfw_images
```

The HTTP transport needs no configuration. `DrupflareServiceProvider` rewrites
`http_handler_stack`'s arguments, and `HandlerStack::create($handler)` takes the transport as its
first argument, so every consumer of `\Drupal::httpClient()` is redirected with no module changes.
On a build **without JSPI** this override installs the handler that needs it; `CfwDeferredHttp` is
the alternative and is selected per service.

The stream wrapper must be registered before anything touches a URL. Enabling the module is enough:
`drupflare.module` registers it and `drupflare.install` covers the one request the module file
cannot.

```php
use Drupal\drupflare\Logger\CfwLogger;
use Drupal\drupflare\StreamWrapper\HttpsStreamWrapper;

// only needed on a host that does not load Drupal modules the normal way
HttpsStreamWrapper::register();
CfwLogger::installFatalHandler();
```

**Registration lives at the top level of `drupflare.module`.** A stream wrapper has to exist before
any code that uses one runs, and no hook is that early: `hook_boot` is gone, a service provider runs
only on a container rebuild, and an event subscriber is later still. The include itself runs on every
request and is module-owned. `ModuleHandler::loadAll()` is called from `DrupalKernel::preHandle()` at
`core/lib/Drupal/Core/DrupalKernel.php:613`, three lines before the kernel registers core's own
wrappers at `:616`.

The Worker host also calls `HttpsStreamWrapper::register()` earlier, from `src/drupal/site-php.ts`,
covering the window before a kernel exists. The module file covers every other host. Installing the
fatal handler stays the host's job.

The request resetter is called by the host between requests:

```php
\Drupal::service('drupflare.request_resetter')->reset();
```

Its return value is a diagnostic: which services were reset, which failed, and how many `PageCache`
instances were cleared. A **zero** cleared count means the middleware chain changed shape and a
persistent kernel is about to serve stale pages, so treat it as a failure rather than a no-op.

---

## 🧪 Testing

| Lane                       | Command                                    | Count    | Needs                              |
| -------------------------- | ------------------------------------------ | -------- | ---------------------------------- |
| syntax                     | `php tests/lint.php`                       | 44 files | nothing but PHP                    |
| the health layer           | `php tests/health-suite.php`               | **553**  | nothing but PHP                    |
| class loading and refusals | `php tests/load-classes.php <drupal-root>` | **94**   | a Drupal 11.3+ root with `vendor/` |
| the capabilities executing | `curl localhost:8787/capability`           | **26**   | `drupflare/worker` running         |

Each suite ends in `exit()`, so coverage runs one per process. With no suite named it runs them
all, one child each, and reports every one:

```sh
php tests/coverage.php        # every suite
php tests/coverage.php health # just one
php tests/coverage.php classes /path/to/drupal-root
```

`php -l` cannot see a linkage error against real Drupal. `CfwImageToolkit` was lint-clean with a
guaranteed fatal in it:

```txt
Fatal error: Class Drupal\drupflare\ImageToolkit\CfwImageToolkit contains 2
abstract methods and must therefore be declared abstract or implement the remaining
methods (PluginFormInterface::buildConfigurationForm, ::submitConfigurationForm)
```

`ImageToolkitBase` implements `PluginFormInterface` but leaves those two abstract, so the class was
not loadable at all, and the error is raised the first time something autoloads it.
`tests/load-classes.php` is the gate: it loads all nine classes under `E_ALL`, asserts each is
instantiable, checks every plugin id and every `services.yml` class reference, and drives the
absent-capability controls, where a missing capability must refuse **by name** rather than return
something plausible.

**The floor is Drupal 11.3.** `ImageToolkitInterface` has extended `ContainerFactoryPluginInterface`
in every version, but `PluginBase::create()` only exists from 11.3.0
(`core/lib/Drupal/Core/Plugin/PluginBase.php:40`, where `PluginBase` also gained
`AutowiredInstanceTrait` at line 19). Below that it stays abstract and the class cannot be declared:

| Drupal | `tests/load-classes.php`      |
| ------ | ----------------------------- |
| 11.0.0 | fatal, `create` unimplemented |
| 11.1.0 | fatal, `create` unimplemented |
| 11.2.0 | fatal, `create` unimplemented |
| 11.3.0 | **passes**                    |
| 11.4.0 | passes                        |

No 11.2.x patch release backported the trait, checked 11.2.1 through 11.2.7, so the floor is the
minor rather than a patch inside it. `build.yml`'s `classes` job runs both **11.3.0 and 11.4.5**, so
the floor stays tested.

The 26 assertions need the host functions installed, which needs a Worker. They cover `cfwLog`
arriving host-side, the stream wrapper reading a prefetched URL exactly (559 bytes), the cached ->
deferred layering returning 200 and 202, `CfwMail` returning FALSE with a logged reason when no
binding exists, and a control of six capabilities present against a seventh absent.

---

## 🧱 Platform Limits

Properties of the runtime.

- **The runtime advertises `http` and `https`, and reading them kills the invocation.** On
  `static-free-v1`, `stream_get_wrappers()` returns
  `compress.zlib,php,file,glob,data,http,https`, and reading through the native wrapper throws
  `ReferenceError: Asyncify is not defined` from inside the wasm import. That is a **JS** exception:
  `@` does not suppress it, PHP's `catch (Throwable)` never sees it, and the whole invocation dies.
  Any vendor or contrib `file_get_contents('https://...')` is an uncatchable invocation-killer, which
  makes registering `HttpsStreamWrapper` over it mandatory. The object recovers; the request does
  not.
- **The stream wrapper does not stream.** The whole body is fetched on open and served from memory,
  because `stream_read()` is synchronous and a streaming read would have to suspend the interpreter
  mid-call.
- **`url_stat()` reports "exists, unknown size".** A HEAD would cost a subrequest per stat, and PHP
  stats speculatively.
- **Mail is one-way.** The binding takes `to`, `from`, `replyTo`, `subject`, `text` and `html` and
  rejects unknown headers, so only `Cc`, `Bcc`, `In-Reply-To` and `References` pass through.
- **An image style produces no derivative file.** See `CfwImageToolkit` above.
- **`Host::call()` cannot carry a wide integer as a number.** It goes through `pw_encode()` and
  arrives as a decimal string or a codec envelope; a plain number above 2^31 wraps.

---

## 🔗 Related Repositories

| Repository                                                          | What it is                                                                                 |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| [`drupflare/worker`](https://github.com/drupflare/worker)           | the site: the Worker, the Durable Object and the packing pipeline                          |
| [`drupflare/rom`](https://github.com/drupflare/rom)                 | `composer require drupflare/rom` - the Drupal 11 database driver for Durable Object SQLite |
| [`drupflare/stream-http`](https://github.com/drupflare/stream-http) | the `https://` stream wrapper `HttpsStreamWrapper` extends; a hard `require`               |
| [`drupflare/phasm`](https://github.com/drupflare/phasm)             | the PHP-to-WebAssembly build that produces the interpreter both modules run inside         |

This module does **not** require the driver, and the driver does not require this module; they share
no class and no service. Each lists the other in `suggest`, because a Worker deployment normally
wants both.

---

## 🤝 Contributing

Clone the repositories as siblings and point Composer at the local checkout with a path repository.
It symlinks, so edits are live with no reinstall.

```sh
composer install
composer run lint       # phpcs: docs, naming, API misuse
composer run analyze    # phpstan level 5, --memory-limit=1G
bunx prettier --check . # layout, every language including PHP

php tests/health-suite.php                             # 553 assertions
DRUPAL_ROOT=/path/to/drupal php tests/load-classes.php # 94; loads every class for real
```

Layout is Prettier's, tabs rendered 4 wide at `printWidth` 100. `phpcs.xml.dist` gives up the sniffs
that disagree with it and keeps every semantic one. PHPStan needs `--memory-limit=1G`; the 128M
default OOMs partway through and reports a small error count that reads like a pass.

## 📄 License

MIT (c) Gregory Mitchell 2026. See [LICENSE](LICENSE).
