# drupflare

The Drupal 11 module that bridges Drupal to Cloudflare Workers bindings: mail, outbound HTTP, images,
queues, logging, a `https://` stream wrapper, request isolation, and a health/self-repair layer.
Published as `drupflare/drupflare`; consumed by `drupflare/worker`.

## This module exists TWICE and the second copy is what executes

`drupflare/worker` keeps its own copy under `drupal/drupflare/`, because **Composer never runs on
the edge**. The worker packs that copy into `assets/driver.json`, which the Durable Object mounts into
its in-memory filesystem. So:

- **This repo's suite is the authority on behaviour.** `php tests/health-suite.php` - **177**
  assertions, plus `DRUPAL_ROOT=<worker>/drupal-src php tests/load-classes.php` - **77**.
  Re-measure before quoting either; both have been stale here in both directions.
- A fix made only in the worker ships **untested**; a fix made only here **never reaches the edge**.
- The worker's `bun run check:sync` compares both copies plus the packed artifact. Run the worker's
  `bun run assets:driver` after any change here, or the packed copy goes stale - it has done so twice.

Drupal **11.3 or newer**, and that is measured rather than cautious: `CfwImageToolkit` is a
guaranteed fatal below it, because `ImageToolkitInterface extends ContainerFactoryPluginInterface`
in every version but `PluginBase::create()` only exists from **11.3.0**, where `PluginBase` gained
`AutowiredInstanceTrait`. The earlier claim here was "from 11", which was inference from one 11.x
checkout: `php tests/load-classes.php` fatals on 11.0.0, 11.1.0 and 11.2.0 and passes clean on
11.3.0. No 11.2.x patch backported the trait.

PHP **8.3 or newer**, matrixed across 8.3, 8.4 and 8.5 in `build.yml`, matching `rom` and
`stream-http`. Measured on 8.5.7 with `error_reporting=E_ALL`: 177 health and 77 class-loading
assertions pass with no deprecation notices, and `src/` carries no implicit nullable parameters or
dynamic properties.

The class-loading job stays pinned to **8.3** rather than matrixed, and that is deliberate: it
downloads a Drupal tree per run, and what it proves is a DRUPAL version boundary, not a PHP one.
Pinning it to the floor of the supported range is the cheapest way to keep proving the floor works.
(The pin carries no inline comment because workflow files take none.)

## Formatting: prettier owns layout, phpcs owns meaning

PHP is formatted by `@prettier/plugin-php` at the house style - **TABS rendered 4 wide, 100-char
lines** - the same as every other language here. It is NOT 2-space Drupal layout; the `.prettierrc`
that forced `useTabs: false, tabWidth: 2` was wrong and was corrected on 2026-08-12. The only
override left is YAML, because tabs are invalid YAML, which is a real technical boundary rather
than a preference.

Constants are lowercase `true`/`false`/`null`, following what this workspace ships (mantle2: 1,276
lowercase vs 1 uppercase), not Drupal core's legacy style.

phpcs cannot also be right about layout, so `phpcs.xml.dist` excludes the whitespace, brace-position
and casing sniffs and keeps everything semantic. The exclusion set is the one worked out in the
sibling driver repo against the same formatter; the reasoning is inline there and here.

**`Drupal.Arrays.Array.ArrayIndentation` IS excluded now, reversing what this file used to say.**
That instruction was written while the tree was 2-space formatted, where the sniff could fire for a
real reason. Under tabs it asserts "parent indent + 2 spaces" against a file with no indent spaces
to count, so it fires on every array element regardless of content and can never pass. A rule that
cannot pass carries no signal.

Two file-scoped exclusions are correct and must stay: `ScopeNotCamelCaps` for the stream wrapper (PHP
looks up `stream_open`, `url_stat` and the rest **literally**; renaming them silently stops the wrapper
working) and `GlobalDrupal` for `RequestResetter::verify()`, whose whole job is to read the same global
container the rest of the request reads - injecting the service would observe a different object and
the check would pass while the bug remained.

**A malformed `phpcs.xml.dist` fails silently and reports a fake pass.** Verify a ruleset change by
loading it. `--` inside an XML comment is invalid and does exactly this.

## The module registers its OWN http/https wrapper now, from the top of `drupflare.module`

This reverses "there is no correct place inside Drupal's bootstrap to put it", which was wrong.
`ModuleHandler::loadAll()` includes the `.module` file from `DrupalKernel::preHandle()` at
`core/lib/Drupal/Core/DrupalKernel.php:613`, which is module-owned, runs every request, and is
three lines earlier than the kernel's own `stream_wrapper_manager->register()` at `:616`. What is
true is that no HOOK is that early; the include is not a hook.

- **Do NOT move `public`/`private` there.** Those are core's schemes and `registerWrapper()`
  unregisters before it registers, so a claim from the module file is replaced by `PublicStream`
  three lines later and LOOKS like it worked. `CfwFileStreamWrapper` stays host-registered until
  the container points those schemes at it, which is a product decision.
- `drupflare.install` carries only `hook_install` (the enabling request built its module list
  before this module was on it, so the include never ran) and `hook_uninstall`.
- **Requirements are classes, not `drupflare_requirements()`.** Drupal 11.3 deprecates a
  procedural `<module>_requirements()` with no `#[LegacyRequirementsHook]`
  (`HookCollectorPass.php:639`). Install phase is
  `src/Install/Requirements/DrupflareRequirements.php` implementing `InstallRequirementsInterface`
  (found by `includes/install.inc:853`); runtime is `src/Hook/Requirements.php` with
  `#[Hook('runtime_requirements')]`. Both use `new TranslatableMarkup(...)` rather than `t()` or
  `StringTranslationTrait`, so `tests/load-classes.php` can call them with no container.
- **Severity is the `RequirementSeverity` enum**, not `REQUIREMENT_*`; an int is deprecated from
  11.2. An unregistered `http`/`https` is `Error` because the native wrapper throws an uncatchable
  JS `ReferenceError`; a missing capability is `Warning` because blocking the install on a plain
  PHP host is worse than a module that refuses at the point of use.
- **The packer now takes `.module` and `.install` too.** `worker/scripts/gen-driver-assets.ts`
  matched only `.php` and `.yml`, so the module-owned registration path shipped nowhere; the walk
  filter was widened and both files reach the edge.

## Rules

- Never silence PHPStan with an ignore, baseline entry, `assert()`, inline `@var`, cast, or widened
  type. Fix the cause. Run it with `--memory-limit=1G`; the default 128M OOMs and reports a fake
  "2 errors", which is not a pass.
- `phpcbf` is trustworthy for whitespace - it once reformatted 426 lines with the suite still green -
  but exclude `Drupal.Commenting.FunctionComment` and `.VariableComment`; it "fixes" a missing
  docblock by inserting an empty stub.
- `RepairLadder::maySafelyRepair()` **fails closed**. Keep it that way; a repair that runs while a
  transaction is open is worse than no repair.
- Comments: lowercase, terse, one line, no trailing period, only where the WHY is non-obvious.

## Publishing

Published on Packagist as `drupflare/drupflare`, currently **v0.1.0**, so `composer require
drupflare/drupflare` resolves with no repository stanza. The Packagist steps themselves are
maintainer-only.

`drupflare/stream-http: ^0.1` is a hard `require` and resolves against its published v0.1.1.

A **local** checkout still needs a Composer path repository, and a path repository reports the branch
rather than a tag: `^0.1` against a path repo fails with `found drupflare/drupflare[dev-main] but it
does not match the constraint`. Use `*@dev` for the path-repo case only, never in a published
constraint.
