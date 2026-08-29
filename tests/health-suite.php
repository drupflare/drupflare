<?php

/**
 * @file
 * Drives the health layer without a Drupal root.
 *
 * Every tripwire is asserted BOTH ways: the observation that must trip it, and the nearby one that
 * must not. This project's rule is that a differential which cannot fail proves nothing, and the
 * health layer's own rule is stronger -- a tripwire nobody has seen fire is decoration. So each
 * check here IS the fault injection for its wire.
 *
 * Runs standalone because the classes are pure functions of an observation array; only HealthLedger
 * needs the host bridge, and it is exercised through its refusal path.
 */

$root = __DIR__ . '/..';

/**
 * Where a PSR-4 root may live, in the order a checkout is likely to have it.
 *
 * `Drupflare\\StreamHttp\\` is a SEPARATE repository this module requires through composer, and the
 * gate checks the siblings out without running composer -- so in CI there is no `vendor/` mapping it
 * and `HttpsStreamWrapper` is simply absent. That was invisible while an earlier step failed first;
 * once it passed, every CachedFetchHandler case here died on a class-not-found. The packer already
 * reads `../stream-http/src` for the same reason, and `STREAM_HTTP_SRC` relocates it the same way.
 */
$psr4 = [
	'Drupal\\drupflare\\' => [$root . '/src'],
	'Drupflare\\StreamHttp\\' => array_filter([
		getenv('STREAM_HTTP_SRC') ?: null,
		$root . '/vendor/drupflare/stream-http/src',
		$root . '/../stream-http/src',
		$root . '/../../stream-http/src',
	]),
];

spl_autoload_register(function (string $class) use ($psr4): void {
	foreach ($psr4 as $prefix => $roots) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}
		$rel = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		foreach ($roots as $dir) {
			$file = $dir . '/' . $rel;
			if (is_file($file)) {
				require_once $file;
				return;
			}
		}
	}
});

$pass = 0;
$fail = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $label
 *   What the assertion claims.
 * @param bool $condition
 *   The result of evaluating that claim.
 * @param string $detail
 *   Extra context printed only on failure.
 */
function ok(string $label, bool $condition, string $detail = ''): void
{
	global $pass, $fail;
	if ($condition) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
	}
}

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Ops\CommandLine;
use Drupal\drupflare\Health\BootSelfTest;
use Drupal\drupflare\Health\CircuitBreaker;
use Drupal\drupflare\Health\Finding;
use Drupal\drupflare\Health\HealthLedger;
use Drupal\drupflare\Health\RepairLadder;
use Drupal\drupflare\Health\TripwireRegistry;
use Drupal\drupflare\Lock\CfwLockBackend;
use Drupal\drupflare\Ops\OpsRegistry;
use Drupal\drupflare\Health\Tripwire\AccountNotRestored;
use Drupal\drupflare\Health\Tripwire\CacheAnonymousPurity;
use Drupal\drupflare\Health\Tripwire\CacheHeaderMissing;
use Drupal\drupflare\Health\Tripwire\ConfigDrift;
use Drupal\drupflare\Health\Tripwire\DbTxnLeaked;
use Drupal\drupflare\Health\Tripwire\SqlLikeOver50;
use Drupal\drupflare\Health\Tripwire\SqlParamsOver100;
use Drupal\drupflare\Shim\CryptoShim;
use Drupal\drupflare\Shim\CurlShim;
use Drupal\drupflare\Shim\ShimRefusal;
use Drupal\drupflare\Shim\ShimRegistry;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\DatabaseBackendFactory;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Lock\PersistentDatabaseLockBackend;
use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Routing\MatcherDumper;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Session\UserSession;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\drupflare\Cache\CfwCacheBackendFactory;
use Drupal\drupflare\DrupflareServiceProvider;
use Drupal\drupflare\Host;
use Drupal\drupflare\Http\CachedFetchHandler;
use Drupal\drupflare\Http\FetchHandler;
use Drupal\drupflare\Logger\CfwLogger;
use Drupal\drupflare\RequestResetter;
use Drupal\drupflare\Routing\CfwMatcherDumper;
use Drupal\drupflare\StreamWrapper\CfwFileStreamWrapper;
use Drupal\page_cache\StackMiddleware\PageCache;
use GuzzleHttp\HandlerStack;
use Symfony\Component\DependencyInjection\Definition;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Drupal\Component\Datetime\Time;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\drupflare\Cache\CfwCacheBackend;
use Drupal\drupflare\Plugin\Mail\CfwMail;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\State\StateInterface;
use Drupal\drupflare\Hook\Requirements;
use Drupal\drupflare\Plugin\ImageToolkit\CfwImageToolkit;
use Drupal\drupflare\Queue\CfwDeferredHttp;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;

// CurlShim's exec() path genuinely needs Guzzle's PSR-7, which is a composer dependency rather
// than a Drupal one, so the shim section is the one part of this suite that reads vendor/.
// Two candidates because this file exists in two places: the module repo has its own vendor/,
// and the worker's packed copy sits beside a full Drupal tree instead.
foreach (
	[$root . '/vendor/autoload.php', $root . '/../../drupal-src/vendor/autoload.php']
	as $candidate
) {
	if (is_file($candidate)) {
		require_once $candidate;
		break;
	}
}
$hasPsr7 = class_exists('GuzzleHttp\\Psr7\\Request');

/**
 * Whether a callable refused, which is the only correct outcome for a shim that cannot serve.
 *
 * Declared BELOW the `use` block on purpose: a `use` alias applies only from its own line on, so
 * a `catch (ShimRefusal)` written above it silently resolves to a non-existent global class and
 * catches nothing. That cost one debugging round here.
 *
 * @param callable $fn
 *   The call to attempt.
 *
 * @return bool
 *   TRUE when it threw a ShimRefusal specifically; any other throwable is a bug, not a refusal.
 */
function throws(callable $fn): bool
{
	try {
		$fn();
		return false;
	} catch (ShimRefusal $e) {
		return true;
	}
}

/**
 * The refusal message, so an assertion can check that it actually names something.
 *
 * @param callable $fn
 *   The call to attempt.
 *
 * @return string
 *   The message, or an empty string when nothing was refused.
 */
function refusal_message(callable $fn): string
{
	try {
		$fn();
		return '';
	} catch (ShimRefusal $e) {
		return $e->getMessage();
	}
}

echo "\n# Finding truncates rather than trusting its input\n";
$long = new Finding('x', Finding::WARN, str_repeat('s', 500), str_repeat('c', 5000));
ok(
	'context is capped',
	strlen($long->context) === Finding::MAX_CONTEXT,
	(string) strlen($long->context),
);
ok('scope is capped', strlen($long->scope) === 120);
ok('toArray carries all four fields', count($long->toArray()) === 4);

echo "\n# cache.anonymous_purity -- the uid-1 disclosure that shipped\n";
$w = new CacheAnonymousPurity();
ok(
	'fires on a non-anonymous page-cache write',
	$w->check(['writing_page_cache' => true, 'uid' => 1])?->severity === Finding::CRITICAL,
);
ok(
	'names uid as the reason',
	str_contains($w->check(['writing_page_cache' => true, 'uid' => 1])->context, 'uid 1'),
);
ok(
	'fires on a Set-Cookie',
	$w->check(['writing_page_cache' => true, 'set_cookie' => true]) !== null,
);
ok(
	'fires on a session cache context',
	$w->check(['writing_page_cache' => true, 'cache_contexts' => ['session']]) !== null,
);
$anon = [
	'writing_page_cache' => true,
	'uid' => 0,
	'cache_contexts' => ['url'],
];
ok('silent on a genuinely anonymous write', $w->check($anon) === null);
ok('silent when not writing the page cache at all', $w->check(['uid' => 1]) === null);

echo "\n# cache.header_missing -- RULE 3 as an assertion\n";
$w = new CacheHeaderMissing();
ok('fires on a render with no header', $w->check(['is_render' => true]) !== null);
ok(
	'silent when the header is present',
	$w->check(['is_render' => true, 'drupal_cache_header' => 'MISS']) === null,
);
ok('silent when it was not a render', $w->check([]) === null);

echo "\n# db.txn_leaked\n";
$w = new DbTxnLeaked();
ok(
	'fires on a depth above zero',
	$w->check(['transaction_depth' => 2])?->severity === Finding::ERROR,
);
ok('silent at zero', $w->check(['transaction_depth' => 0]) === null);

echo "\n# account.not_restored -- the other half of the disclosure\n";
$w = new AccountNotRestored();
ok(
	'fires on a non-empty switcher stack',
	$w->check(['switcher_depth' => 1, 'uid' => 1])?->severity === Finding::CRITICAL,
);
ok('silent on an empty stack', $w->check(['switcher_depth' => 0]) === null);

echo "\n# sql.params_over_100 -- measured ceiling, 100 ok and 101 throws\n";
$w = new SqlParamsOver100();
ok('silent at exactly 100', $w->check(['param_count' => 100]) === null);
ok('fires at 101', $w->check(['param_count' => 101]) !== null);
ok(
	'fires on the real cache_discovery shape, 574',
	str_contains($w->check(['param_count' => 574])->context, '574'),
);

echo "\n# sql.like_over_50 -- on the TRANSLATED pattern, which is the point\n";
$w = new SqlLikeOver50();
ok('silent at exactly 50 bytes', $w->check(['translated_pattern' => str_repeat('a', 50)]) === null);
ok('fires at 51 bytes', $w->check(['translated_pattern' => str_repeat('a', 51)]) !== null);
// 20 asterisks bracket-quote to 60 bytes, so an input that looked safe is not
ok(
	'fires on 20 bracket-quoted asterisks',
	$w->check(['translated_pattern' => str_repeat('[*]', 20)]) !== null,
);
ok('silent when no pattern was supplied', $w->check([]) === null);

echo "\n# config.drift -- three settings this runtime cannot survive\n";
$w = new ConfigDrift();
ok(
	'fires when automated_cron is re-enabled',
	str_contains($w->check(['automated_cron_interval' => 10800])->context, 'automated_cron'),
);
ok(
	'fires when advisories are re-enabled',
	str_contains($w->check(['advisories_enabled' => true])->context, 'advisories'),
);
ok(
	'fires when dblog is reinstalled',
	str_contains($w->check(['dblog_installed' => true])->context, 'dblog'),
);
ok(
	'reports all three at once',
	substr_count(
		$w->check([
			'automated_cron_interval' => 1,
			'advisories_enabled' => true,
			'dblog_installed' => true,
		])->context,
		';',
	) === 2,
);
ok(
	'silent on a correctly configured site',
	$w->check([
		'automated_cron_interval' => 0,
		'advisories_enabled' => false,
		'dblog_installed' => false,
	]) === null,
);

echo "\n# the registry\n";
$reg = new TripwireRegistry();
ok('holds seven wires', count($reg->all()) === 7);
ok(
	'every wire declares a dotted code',
	count(array_filter($reg->all(), static fn($x) => str_contains($x->code(), '.'))) === 7,
);
ok(
	'codes are unique',
	count(array_unique(array_map(static fn($x) => $x->code(), $reg->all()))) === 7,
);
$sick = $reg->run([
	'writing_page_cache' => true,
	'uid' => 1,
	'transaction_depth' => 1,
	'switcher_depth' => 2,
	'param_count' => 700,
]);
ok('finds four on a genuinely sick observation', count($sick) === 4, (string) count($sick));
ok(
	'finds nothing on a healthy one',
	$reg->run([
		'transaction_depth' => 0,
		'switcher_depth' => 0,
		'param_count' => 12,
		'automated_cron_interval' => 0,
		'advisories_enabled' => false,
	]) === [],
);

echo "\n# the ladder, and the rule that a repair never runs inside a transaction\n";
ok('critical starts at quarantine', RepairLadder::initialRung(Finding::CRITICAL) === 'quarantine');
ok('error starts at reset', RepairLadder::initialRung(Finding::ERROR) === 'reset');
ok('warn starts at observe', RepairLadder::initialRung(Finding::WARN) === 'observe');
ok('escalate saturates at the top', RepairLadder::escalate('rollback') === 'rollback');
ok('decay bottoms out at NULL', RepairLadder::decay('observe') === null);
ok(
	'REFUSES to repair inside an open transaction',
	RepairLadder::maySafelyRepair(['transaction_depth' => 1]) === false,
);
ok(
	'refuses while the gate is held',
	RepairLadder::maySafelyRepair(['transaction_depth' => 0, 'gate_held' => true]) === false,
);
ok(
	'fails CLOSED when the depth is unknown',
	RepairLadder::maySafelyRepair(['gate_held' => false]) === false,
);
$clean = ['transaction_depth' => 0, 'gate_held' => false];
ok('permits a repair when nothing is open', RepairLadder::maySafelyRepair($clean) === true);

echo "\n# the breaker escalates on repetition, decays on quiet, never loops\n";
$b = new CircuitBreaker(60000, 3);
ok('first hit does not escalate', $b->record('x', Finding::WARN, 1000) === 'observe');
$b->record('x', Finding::WARN, 1100);
ok('third hit inside the window escalates', $b->record('x', Finding::WARN, 1200) === 'reset');
$b2 = new CircuitBreaker(1000, 3);
$b2->record('y', Finding::WARN, 0);
$b2->record('y', Finding::WARN, 100);
ok('hits outside the window are forgotten', $b2->record('y', Finding::WARN, 5000) === 'observe');
$b3 = new CircuitBreaker(60000, 1);
$b3->record('z', Finding::ERROR, 0);
ok('one hit at threshold 1 escalates from reset', $b3->rungOf('z') === 'reconstruct');
$b3->decay();
ok('a clean interval decays one rung', $b3->rungOf('z') === 'reset');
$b3->decay();
$b3->decay();
ok('decays out of the map entirely, so it cannot grow unbounded', $b3->rungOf('z') === null);
$b4 = new CircuitBreaker(60000, 1);
for ($i = 0; $i < 20; $i++) {
	$b4->record('w', Finding::CRITICAL, $i);
}
ok('never escalates past the ladder top', $b4->rungOf('w') === 'rollback');
ok('codes are independent', (new CircuitBreaker())->rungOf('never-seen') === null);

echo "\n# the boot self-test refuses to serve rather than serving wrong output\n";
$healthy = [
	'bridge_installed' => true,
	'missing_capabilities' => [],
	'sqlite_version' => '3.46.0',
	'migrate_chunk' => 99,
	'migrate_chunks' => 99,
	'updb_phase' => 'complete',
	'pack_generation' => 'a',
	'db_generation' => 'a',
	'automated_cron_interval' => 0,
	'advisories_enabled' => false,
];
ok('a healthy boot reports nothing', BootSelfTest::run($healthy) === []);
ok('a healthy boot may serve', BootSelfTest::mayServe(BootSelfTest::run($healthy)) === true);
ok(
	'a missing bridge is critical',
	BootSelfTest::run(['bridge_installed' => false])[0]->severity === Finding::CRITICAL,
);
ok(
	'refuses to serve without the bridge',
	BootSelfTest::mayServe(BootSelfTest::run(['bridge_installed' => false])) === false,
);
$old = BootSelfTest::run(['bridge_installed' => true, 'sqlite_version' => '3.44.0']);
ok(
	'3.44 fails the 3.45 floor Drupal gates on',
	count(array_filter($old, static fn($f) => $f->code === 'boot.sqlite_too_old')) === 1,
);
ok(
	'3.45 passes the floor',
	count(
		array_filter(
			BootSelfTest::run($healthy + ['sqlite_version' => '3.45']),
			static fn($f) => $f->code === 'boot.sqlite_too_old',
		),
	) === 0,
);
$half = BootSelfTest::run([
	'bridge_installed' => true,
	'migrate_chunk' => 24,
	'migrate_chunks' => 99,
]);
ok('a half-migrated site refuses to serve', BootSelfTest::mayServe($half) === false);
ok(
	'a site with NO cursor is not flagged',
	count(
		array_filter(
			BootSelfTest::run(['bridge_installed' => true]),
			static fn($f) => $f->code === 'boot.migrate_incomplete',
		),
	) === 0,
);
ok(
	'a generation mismatch refuses to serve',
	BootSelfTest::mayServe(
		BootSelfTest::run([
			'bridge_installed' => true,
			'pack_generation' => 'a',
			'db_generation' => 'b',
		]),
	) === false,
);
ok(
	'a halted updb is reported but not fatal',
	BootSelfTest::mayServe(BootSelfTest::run($healthy + [])) === true,
);
$halted = BootSelfTest::run(array_merge($healthy, ['updb_phase' => 'halted']));
ok(
	'a halted updb is reported',
	count(array_filter($halted, static fn($f) => $f->code === 'boot.updb_halted')) === 1,
);
ok(
	'missing capabilities are named',
	str_contains(
		BootSelfTest::run(['bridge_installed' => true, 'missing_capabilities' => ['cfwMail']])[0]
			->context,
		'cfwMail',
	),
);

echo "\n# the ledger refuses rather than throwing when the host cannot take it\n";
ok(
	'record() returns FALSE with no bridge',
	HealthLedger::record(new Finding('x', Finding::WARN)) === false,
);
ok(
	'recordAll() reports zero written',
	HealthLedger::recordAll([new Finding('x', Finding::WARN)]) === 0,
);

echo "\n# cfw_ops replaces Drush, and every operation declares what it costs\n";
ok('declares the eight operations', count(OpsRegistry::operations()) === 8);
ok(
	'every one has all four fields',
	count(
		array_filter(
			OpsRegistry::operations(),
			static fn($op) => array_key_exists('label', $op) &&
				array_key_exists('writes', $op) &&
				array_key_exists('sliced', $op) &&
				array_key_exists('cost', $op),
		),
	) === 8,
);
ok(
	'status is the only read-only unsliced operation',
	OpsRegistry::readOnlyUnsliced() === ['status'],
);
ok(
	'cr writes and must be sliced, at 28x an invocation',
	OpsRegistry::writes('cr') && OpsRegistry::sliced('cr'),
);
ok('updb writes and must be sliced', OpsRegistry::writes('updb') && OpsRegistry::sliced('updb'));
ok('en writes and must be sliced', OpsRegistry::writes('en') && OpsRegistry::sliced('en'));
ok(
	'sql-dump does not write but is still sliced',
	!OpsRegistry::writes('sql-dump') && OpsRegistry::sliced('sql-dump'),
);
ok(
	'cex is a read but still sliced, like sql-dump',
	!OpsRegistry::writes('cex') && OpsRegistry::sliced('cex'),
);
ok('has() is honest about a real name', OpsRegistry::has('status'));
ok('has() refuses an unknown name', !OpsRegistry::has('sql-query'));
// fails closed: a caller that forgets has() cannot expose a mutation as a read
ok('writes() FAILS CLOSED on an unknown name', OpsRegistry::writes('not-a-command') === true);
ok('sliced() FAILS CLOSED on an unknown name', OpsRegistry::sliced('not-a-command') === true);
ok('no operation is named drush', !OpsRegistry::has('drush'));

echo "\n# the shim registry routes what it can and NAMES what it cannot\n";

ok(
	'every entry declares verdict, via, why and alternative',
	count(
		array_filter(
			ShimRegistry::functions(),
			static fn($e) => array_key_exists('verdict', $e) &&
				array_key_exists('via', $e) &&
				array_key_exists('why', $e) &&
				array_key_exists('alternative', $e),
		),
	) === count(ShimRegistry::functions()),
);
ok(
	'every verdict is one of the three declared values',
	count(
		array_filter(
			ShimRegistry::functions(),
			static fn($e) => in_array($e['verdict'], ShimRegistry::verdicts(), true),
		),
	) === count(ShimRegistry::functions()),
);
ok('there are exactly three verdicts', count(ShimRegistry::verdicts()) === 3);
ok(
	'every why is a real sentence rather than a placeholder',
	count(
		array_filter(ShimRegistry::functions(), static fn($e) => strlen(trim($e['why'])) > 20),
	) === count(ShimRegistry::functions()),
);
ok(
	'every routed entry names the primitive it runs over',
	count(
		array_filter(
			ShimRegistry::functions(),
			static fn($e) => $e['verdict'] !== ShimRegistry::ROUTE || trim($e['via']) !== '',
		),
	) === count(ShimRegistry::functions()),
);

// the five curl functions the task scopes, and no more pretending to exist
foreach (['curl_init', 'curl_setopt', 'curl_exec', 'curl_getinfo', 'curl_close'] as $fn) {
	ok("$fn routes over CfwDeferredHttp", ShimRegistry::via($fn) === 'CfwDeferredHttp');
}
ok(
	'openssl_digest routes over crypto.subtle',
	str_contains(ShimRegistry::via('openssl_digest'), 'crypto.subtle'),
);
ok(
	'hash_hmac routes over crypto.subtle',
	str_contains(ShimRegistry::via('hash_hmac'), 'crypto.subtle'),
);
ok(
	'random_bytes routes over the host CSPRNG',
	str_contains(ShimRegistry::via('random_bytes'), 'crypto'),
);

// #region the refuse-and-name half
foreach (
	[
		'openssl_pkey_new' => 'keypair generation',
		'openssl_pkey_export' => 'keypair export',
		'openssl_csr_new' => 'CSR generation',
		'openssl_private_encrypt' => 'raw private-key encryption',
		'imagecreatetruecolor' => 'gd',
		'exec' => 'exec',
		'shell_exec' => 'shell_exec',
		'proc_open' => 'proc_open',
		'fsockopen' => 'fsockopen',
		'stream_socket_client' => 'raw sockets',
	]
	as $fn => $what
) {
	ok("$fn is refused ($what)", ShimRegistry::isRefused($fn));
	ok("$fn refusal NAMES a reason", strlen(ShimRegistry::reason($fn)) > 20);
}
ok(
	'a gd refusal points at CfwImageToolkit rather than just failing',
	str_contains(ShimRegistry::alternative('imagecreatetruecolor'), 'CfwImageToolkit'),
);

// gd's absence does NOT take ext/standard's header reader with it, and this table said it did.
// `CfwImageToolkit` reads every dimension through getimagesize(), so the wrong entry described the
// toolkit's own dependency as unavailable
ok('getimagesize is NOT refused', !ShimRegistry::isRefused('getimagesize'));
ok('getimagesize is native', ShimRegistry::verdict('getimagesize') === ShimRegistry::NATIVE);
ok('getimagesize exists on this build', function_exists('getimagesize'));
ok(
	'the getimagesize reason names ext/standard rather than gd',
	str_contains(ShimRegistry::reason('getimagesize'), 'ext/standard'),
);

// openssl_sign/verify are bridged over node:crypto, which is synchronous in workerd. The table
// refused them on "crypto.subtle is async", which was never the mechanism
foreach (['openssl_sign', 'openssl_verify'] as $fn) {
	ok("$fn is routed rather than refused", !ShimRegistry::isRefused($fn));
	ok("$fn names node:crypto as its route", ShimRegistry::via($fn) === 'node:crypto');
}
ok(
	'an exec refusal points at OpsRegistry, which is why Drush is not shipped',
	str_contains(ShimRegistry::alternative('exec'), 'OpsRegistry'),
);
ok(
	'a socket refusal explains that fetch() is request-shaped',
	str_contains(ShimRegistry::reason('fsockopen'), 'fetch()'),
);
ok('nothing refused claims a primitive it does not have', ShimRegistry::via('exec') === '');
// #endregion
// #region fails closed, the same way OpsRegistry and RepairLadder do
ok('has() is honest about a listed name', ShimRegistry::has('curl_exec'));
ok('has() is honest about an unlisted name', !ShimRegistry::has('mysqli_connect'));
ok(
	'verdict() FAILS CLOSED on an unknown name',
	ShimRegistry::verdict('mysqli_connect') === ShimRegistry::REFUSE,
);
ok('isRefused() FAILS CLOSED on an unknown name', ShimRegistry::isRefused('not_a_function'));
ok(
	'reason() still names something for a function nobody has heard of',
	str_contains(ShimRegistry::reason('not_a_function'), 'not_a_function') &&
		str_contains(ShimRegistry::reason('not_a_function'), 'refused'),
);
ok(
	'assertRouted() throws for an unknown name',
	throws(static fn() => ShimRegistry::assertRouted('not_a_function')),
);
ok(
	'assertRouted() throws for a refused name',
	throws(static fn() => ShimRegistry::assertRouted('proc_open')),
);
ok(
	'assertRouted() passes a routed name',
	!throws(static fn() => ShimRegistry::assertRouted('curl_init')),
);
ok(
	'the three lists partition the table with nothing left over',
	count(ShimRegistry::routed()) +
		count(ShimRegistry::refused()) +
		count(ShimRegistry::native()) ===
		count(ShimRegistry::functions()),
);
ok('the native list is not empty, which would defeat the point', ShimRegistry::native() !== []);
ok(
	'assertRouted() passes a native name, because nothing is in the way',
	!throws(static fn() => ShimRegistry::assertRouted('getimagesize')),
);
ok(
	'the refused list is not empty, which would defeat the point',
	count(ShimRegistry::refused()) > 0,
);
// #endregion
echo "\n# a refusal carries what was refused and why, never a bare false\n";

$refusal = ShimRegistry::refusal('proc_open');
ok('it is a ShimRefusal', $refusal instanceof ShimRefusal);
ok('it knows the function', $refusal->functionName() === 'proc_open');
ok('its reason is never empty', trim($refusal->reason()) !== '');
ok('its message names the function', str_contains($refusal->getMessage(), 'proc_open()'));
ok('its message carries the alternative', str_contains($refusal->getMessage(), 'Use '));
ok(
	'a refusal with no alternative does not fabricate one',
	!str_contains((new ShimRefusal('x', 'because.'))->getMessage(), 'Use '),
);

echo "\n# the curl subset behaves, and refuses an option it would otherwise drop\n";

$curl = new CurlShim();
$ch = $curl->init('https://example.invalid/a');
ok('init() seeds the URL', $ch['url'] === 'https://example.invalid/a');
ok('init() has not executed', $ch['executed'] === false);
ok(
	'setopt() takes a URL',
	$curl->setopt($ch, 10002, 'https://example.invalid/b') &&
		$ch['url'] === 'https://example.invalid/b',
);
$curl->setopt($ch, 10023, ['X-A: 1', 'X-B: 2', 'malformed']);
ok(
	'setopt() parses a header list and drops the malformed line',
	$ch['headers'] === ['X-A' => '1', 'X-B' => '2'],
);
$curl->setopt($ch, 10015, ['q' => 'x']);
ok('setopt() form-encodes an array body', $ch['body'] === 'q=x');
ok(
	'setopt() coerces a flag to bool',
	$curl->setopt($ch, 19913, 1) && $ch['returntransfer'] === true,
);
// the load-bearing one: a dropped VERIFYPEER is a security change the caller thinks it made
ok(
	'setopt() REFUSES an unimplemented option instead of ignoring it',
	throws(static function () use ($curl, $ch) {
		$h = $ch;
		// 64 is CURLOPT_SSL_VERIFYPEER
		$curl->setopt($h, 64, false);
	}),
);
ok(
	'the refusal names the option number',
	str_contains(
		refusal_message(static function () use ($curl, $ch) {
			$h = $ch;
			$curl->setopt($h, 64, false);
		}),
		'64',
	),
);
$arrayHandle = $curl->init('https://example.invalid/c');
ok(
	'setopt_array() refuses the WHOLE array on one bad option',
	throws(static function () use ($curl, $arrayHandle) {
		$h = $arrayHandle;
		$curl->setoptArray($h, [10002 => 'https://example.invalid/d', 64 => false]);
	}),
);
ok(
	'and leaves the handle untouched when it refuses',
	$arrayHandle['url'] === 'https://example.invalid/c',
);
$okArray = $curl->init();
ok(
	'setopt_array() applies every option when they are all understood',
	$curl->setoptArray($okArray, [10002 => 'https://example.invalid/e', 47 => 1]) &&
		$okArray['url'] === 'https://example.invalid/e' &&
		$okArray['post'] === true,
);
ok(
	'getinfo() REFUSES before exec(), because empty info reads as a failed request',
	throws(static fn() => $curl->getinfo($okArray)),
);
$noUrl = $curl->init();
ok(
	'exec() refuses with no URL rather than requesting nothing',
	throws(static function () use ($curl, $noUrl) {
		$h = $noUrl;
		$curl->exec($h);
	}),
);
ok('errno() is CURLE_OK on a fresh handle', $curl->errno($okArray) === CurlShim::CURLE_OK);
ok('error() is empty on a fresh handle', $curl->error($okArray) === '');
$closing = $curl->init('https://example.invalid/f');
$curl->close($closing);
ok('close() empties the handle so a reuse fails loudly', $closing === []);

echo "\n# curl_exec with no host bridge reports a 202/503, never a silent empty body\n";

// Skipped rather than faked when PSR-7 is absent: a stub Request would assert that the stub
// works. The packed copy of this file sits beside a Drupal tree, so it normally finds one.
if (!$hasPsr7) {
	echo "  skip guzzlehttp/psr7 not on the include path; exec() assertions need a real request\n";
} else {
	// no vrzno here, so Host::call refuses and CfwDeferredHttp answers 503 x-cfw-deferred: failed.
	// That is the real path, not a mock: the point is that a caller can TELL.
	$live = $curl->init('https://example.invalid/g');
	$curl->setopt($live, 19913, 1);
	$body = $curl->exec($live);
	ok('exec() returns FALSE rather than an empty string', $body === false);
	ok('errno() is set, not left at OK', $curl->errno($live) === CurlShim::CURLE_COULDNT_CONNECT);
	ok('error() names CfwDeferredHttp', str_contains($curl->error($live), 'CfwDeferredHttp'));
	ok('getinfo() reports the deferred state', $curl->getinfo($live, 'cfw_deferred') === 'failed');
	ok(
		'getinfo() reports the status the queue produced',
		$curl->getinfo($live, 'http_code') === 503,
	);
	ok(
		'getinfo() reports the method it resolved',
		$curl->getinfo($live, 'request_method') === 'GET',
	);
	ok(
		'getinfo() returns NULL for a field it does not have',
		$curl->getinfo($live, 'primary_ip') === null,
	);
	$posted = $curl->init('https://example.invalid/h');
	$curl->setopt($posted, 10015, 'a=1');
	$curl->exec($posted);
	ok(
		'a body implies POST without CURLOPT_POST',
		$curl->getinfo($posted, 'request_method') === 'POST',
	);
}

echo "\n# crypto routes what crypto.subtle can do and refuses what it cannot\n";

ok('sha256 maps to the SubtleCrypto spelling', CryptoShim::subtleName('sha256') === 'SHA-256');
ok('SHA-256 with the hyphen maps too', CryptoShim::subtleName('SHA-256') === 'SHA-256');
ok(
	'sha1, sha384 and sha512 all map',
	CryptoShim::subtleName('sha1') === 'SHA-1' &&
		CryptoShim::subtleName('sha384') === 'SHA-384' &&
		CryptoShim::subtleName('sha512') === 'SHA-512',
);
// md5 is absent from crypto.subtle by specification, not by omission
ok('md5 does NOT map, because crypto.subtle has no md5', CryptoShim::subtleName('md5') === null);
ok('an invented algorithm does not map', CryptoShim::subtleName('sha3000') === null);
ok('crypto.subtle offers exactly four digests', count(CryptoShim::SUBTLE_DIGESTS) === 4);

// no host bridge here, so these exercise the in-wasm fallback and the refusal
if (CryptoShim::hashAvailable('sha256')) {
	ok(
		'digest() falls back to hash() and agrees with it',
		CryptoShim::digest('abc', 'sha256') === hash('sha256', 'abc'),
	);
	ok(
		'digest() returns raw bytes when asked',
		CryptoShim::digest('abc', 'sha256', true) === hash('sha256', 'abc', true),
	);
	ok(
		'hmac() falls back to hash_hmac() and agrees with it',
		CryptoShim::hmac('sha256', 'abc', 'k') === hash_hmac('sha256', 'abc', 'k'),
	);
} else {
	ok(
		'digest() refuses when nothing can serve it',
		throws(static fn() => CryptoShim::digest('abc', 'sha256')),
	);
	ok(
		'digest() names the missing engine',
		str_contains(
			refusal_message(static fn() => CryptoShim::digest('abc', 'sha256')),
			'hash extension',
		),
	);
	ok(
		'hmac() refuses when nothing can serve it',
		throws(static fn() => CryptoShim::hmac('sha256', 'abc', 'k')),
	);
}
ok(
	'digest() REFUSES an algorithm no engine here has',
	throws(static fn() => CryptoShim::digest('abc', 'sha3000')),
);
ok(
	'and the refusal lists what crypto.subtle actually implements',
	str_contains(refusal_message(static fn() => CryptoShim::digest('abc', 'sha3000')), 'SHA-256'),
);
// an empty-key MAC is forgeable by anyone; returning one would hide the mistake
ok('hmac() refuses an empty key', throws(static fn() => CryptoShim::hmac('sha256', 'abc', '')));
ok(
	'and says why an empty key is refused',
	str_contains(refusal_message(static fn() => CryptoShim::hmac('sha256', 'abc', '')), 'forge'),
);
ok('randomBytes() refuses a zero length', throws(static fn() => CryptoShim::randomBytes(0)));
ok('randomBytes() refuses a negative length', throws(static fn() => CryptoShim::randomBytes(-8)));
ok(
	'randomBytes() returns exactly the length asked for',
	strlen(CryptoShim::randomBytes(16)) === 16,
);
ok(
	'randomBytes() does not repeat itself',
	CryptoShim::randomBytes(16) !== CryptoShim::randomBytes(16),
);
ok('hashAvailable() refuses an invented algorithm', !CryptoShim::hashAvailable('sha3000'));

// #region Html::$seenIds, the static drupal_static_reset() does not cover
// A persistent interpreter accumulates the id registry across requests, so without an explicit
// reset the second visitor to a page gets `--2` ids and the third `--3`. Measured on a live
// object: /user/login came back with 10 suffixed ids purely because / had rendered first.
// These four stay source assertions because they are about ORDER and about the import style;
// everything the resetter DOES is driven for real further down, against this repo's own
// vendor/drupal/core.
$resetter = file_get_contents(__DIR__ . '/../src/RequestResetter.php');
ok('the resetter clears Html::$seenIds', str_contains($resetter, 'Html::resetSeenIds()'));
ok(
	'it reports the reset, so a leak that survives is visible',
	str_contains($resetter, 'html_seen_ids_reset'),
);
ok(
	'it runs AFTER drupal_static_reset(), which is the step that does not cover it',
	strpos($resetter, 'Html::resetSeenIds()') > strpos($resetter, 'drupal_static_reset()'),
);
ok(
	'Html is imported rather than referenced fully qualified',
	str_contains($resetter, 'use Drupal\Component\Utility\Html;'),
);
// #endregion
// #region the lock that does not need a clock
if (!interface_exists('Drupal\Core\Lock\LockBackendInterface')) {
	eval('namespace Drupal\Core\Lock; interface LockBackendInterface {
		public function acquire($name, $timeout = 30.0);
		public function lockMayBeAvailable($name);
		public function wait($name, $delay = 30);
		public function release($name);
		public function releaseAll($lock_id = NULL);
		public function getLockId();
	}');
}

$lock = new CfwLockBackend();
ok('acquire() grants, because no second thread can hold it', $lock->acquire('router_rebuild'));
ok('acquiring the same name again still grants', $lock->acquire('router_rebuild'));
ok('a name nobody acquired is available', $lock->lockMayBeAvailable('never_taken'));
// the one that matters: true sends RouteBuilder back around to wait again, and waiting is what
// burned 30 seconds of CPU
ok(
	'wait() reports the lock free rather than asking the caller to wait',
	!$lock->wait('router_rebuild'),
);
ok(
	'wait() on a name held by someone else also returns immediately',
	!$lock->wait('held_elsewhere', 30),
);
$lock->release('router_rebuild');
ok('a released lock is still acquirable', $lock->acquire('router_rebuild'));
$lock->releaseAll();
ok('releaseAll() leaves the backend usable', $lock->acquire('router_rebuild'));

$id = $lock->getLockId();
ok('the lock id is stable within one instance', $id === $lock->getLockId());
ok('the lock id is non-empty', $id !== '');
ok('a second instance gets a different id', (new CfwLockBackend())->getLockId() !== $id);

// the differential, and it is the mechanism rather than the behaviour: a backend that reached for
// either of these would reintroduce exactly the failure it replaces
$lockSource = file_get_contents(__DIR__ . '/../src/Lock/CfwLockBackend.php');
$lockCode = substr($lockSource, strpos($lockSource, 'final class'));
ok('it never calls microtime(), which returns 0 here', !str_contains($lockCode, 'microtime('));
ok('it never calls usleep(), which spins here', !str_contains($lockCode, 'usleep('));
ok('it never touches the semaphore table', !str_contains($lockCode, 'semaphore'));

$provider = file_get_contents(__DIR__ . '/../src/DrupflareServiceProvider.php');
ok('the provider swaps `lock`', str_contains($provider, "'lock' => DatabaseLockBackend::class"));
ok(
	'and `lock.persistent`, whose rows outlive a request',
	str_contains($provider, "'lock.persistent' => PersistentDatabaseLockBackend::class"),
);
ok(
	'it clears core arguments, which this backend does not take',
	str_contains($provider, 'setArguments([])'),
);
// core marks both lazy, and a lazy service resolves through a generated proxy class that exists
// only for the class core named -- measured on the edge as "Missing proxy class
// Drupal\drupflare\ProxyClass\Lock\CfwLockBackend" on every container build
ok(
	'it clears `lazy`, or the container looks for a proxy class that was never generated',
	str_contains($provider, 'setLazy(false)'),
);
ok(
	'it guards on the core class, so a site that already overrode the lock keeps its own',
	str_contains($provider, '$definition->getClass() !== $coreClass'),
);
// #endregion
// #region the per-request resetter, driven against real Drupal classes
//
// NOT source assertions. The four above are about ordering and imports, which only the text can
// answer; everything else this class does is reachable here, because drupflare/drupflare REQUIRES
// drupal/core and vendor/autoload.php is already loaded for Guzzle's PSR-7. The note that used to
// say this harness "cannot load Drupal\Component\Utility\Html" was stale.
//
// This is the reactive half of the static-state problem: the interpreter survives between
// requests, Drupal assumes a fresh process, and every leak below was measured on a live object
// rather than imagined.
require_once __DIR__ . '/fixtures/page-cache-stub.php';

/**
 * A service that records its own reset, which is what the tag collector is supposed to reach.
 */
class ResettableSpy
{
	/**
	 * How many times reset() was called.
	 *
	 * @var int
	 */
	public $resets = 0;

	/**
	 * Records the reset.
	 */
	public function reset(): void
	{
		$this->resets++;
	}
}

/**
 * A tagged service whose reset() fails, so one bad service cannot abort the whole reset.
 */
class ExplodingResettable
{
	/**
	 * Always throws.
	 */
	public function reset(): void
	{
		throw new RuntimeException('this service cannot be reset');
	}
}

/**
 * A tagged service with no reset() at all, which must be skipped rather than reported.
 */
class NotResettable {}

/**
 * An account switcher whose stack has a depth, so an unwind can be counted.
 */
class StackedSwitcher
{
	/**
	 * How many switchBack() calls were made.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Builds a switcher with a stack already on it.
	 *
	 * @param int $depth
	 *   How many frames are on the stack.
	 */
	public function __construct(private int $depth) {}

	/**
	 * Pops one frame, or refuses once the stack is empty.
	 */
	public function switchBack(): void
	{
		$this->calls++;
		if ($this->depth <= 0) {
			// core's AccountSwitcher raises on an empty stack, and that raise IS the
			// terminating condition the resetter reads
			throw new RuntimeException('No more account switches to revert.');
		}
		$this->depth--;
	}
}

/**
 * A switcher that never empties, which is the case the bound exists for.
 */
class BottomlessSwitcher
{
	/**
	 * How many switchBack() calls were made.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Never refuses.
	 */
	public function switchBack(): void
	{
		$this->calls++;
	}
}

/**
 * A session service shaped like core's, with the three flags the resetter clears.
 */
class SessionSpy
{
	/**
	 * Whether the session was started this request.
	 *
	 * @var bool
	 */
	public $started = true;

	/**
	 * Whether it was already written and closed.
	 *
	 * @var bool
	 */
	public $closed = true;

	/**
	 * Whether it was started lazily, which core tracks separately.
	 *
	 * @var bool
	 */
	public $startedLazy = true;
}

/**
 * A session service whose flags cannot be written, so the error branch is reachable.
 */
class FrozenSession
{
	/**
	 * Builds a session whose flag cannot be cleared.
	 *
	 * @param bool $started
	 *   Readonly, so a reflection write raises.
	 */
	public function __construct(public readonly bool $started = true) {}
}

/**
 * A current_user that refuses to answer, so resetIdentity() records the failure.
 */
class BrokenAccount
{
	/**
	 * Always throws, which is what resetIdentity() has to survive.
	 *
	 * @throws RuntimeException
	 *   Always; the throw is the whole point of this stub.
	 */
	public function id(): never
	{
		throw new RuntimeException('the account proxy is not usable');
	}
}

/**
 * One link in the middleware chain, holding the next one behind a closure as PageCache does.
 */
class MiddlewareLink
{
	/**
	 * Builds one link of the chain.
	 *
	 * @param object|null $next
	 *   The next link, or a closure producing it.
	 * @param object|null $kernel
	 *   A kernel the walk must never descend into.
	 */
	public function __construct(public ?object $next = null, public ?object $kernel = null) {}
}

/**
 * A chain link whose closure raises, which must be stepped over rather than fatal.
 */
class ThrowingLink
{
	/**
	 * The closure the walk unwraps, which refuses to produce anything.
	 *
	 * @var Closure
	 */
	public $next;

	/**
	 * Builds the raising closure.
	 */
	public function __construct()
	{
		$this->next = static function (): object {
			throw new RuntimeException('this inner kernel cannot be built');
		};
	}
}

/**
 * Builds a container carrying whatever services a case needs.
 *
 * @param array $services
 *   Service id to an already-constructed object.
 *
 * @return ContainerBuilder
 *   A container with every one of them registered AND initialized, because initialized() is what
 *   the resetter branches on.
 */
function resetter_container(array $services): ContainerBuilder
{
	$container = new ContainerBuilder();
	foreach ($services as $id => $service) {
		$container->set($id, $service);
	}
	return $container;
}

echo "\n# the per-request resetter, which is the reactive half of the static-state problem\n";

$spy = new ResettableSpy();
$container = resetter_container([
	'cache.static' => $spy,
	'renderer' => new NotResettable(),
	'node.grant_storage' => new ExplodingResettable(),
]);
$log = (new RequestResetter($container, [
	'cache.static',
	'renderer',
	'node.grant_storage',
	'never.constructed',
]))->reset();

ok('it calls reset() on a tagged service that has one', $spy->resets === 1);
ok(
	'and lists it, so a reset that did not happen is visible',
	$log['services'] === ['cache.static'],
);
ok(
	'a service with no reset() is skipped rather than reported',
	!in_array('renderer', $log['services'], true),
);
// one bad service must not abort the reset: the identity half runs after it
ok(
	'a reset() that throws is recorded against its id',
	str_contains($log['errors']['node.grant_storage'] ?? '', 'cannot be reset'),
);
ok('and the reset carries on past it', isset($log['identity'], $log['session']));
ok(
	'a service that was never constructed is not instantiated to reset it',
	!isset($log['errors']['never.constructed']),
);

echo "\n# the account switcher stack, which is the OTHER half of the disclosure\n";

// THE REGRESSION. The previous unwind compared $switcher to a copy of itself, which is always the
// same object, so it broke on the first pass: a request that had switched twice came back at
// depth 1, and account.not_restored fires on exactly that
$stacked = new StackedSwitcher(3);
$log = (new RequestResetter(resetter_container(['account_switcher' => $stacked]), []))->reset();
ok(
	'it unwinds the WHOLE stack, not one frame of it',
	$stacked->calls === 4,
	(string) $stacked->calls,
);
ok('and reports the empty stack as unwound', ($log['account_switcher'] ?? '') === 'unwound');

$empty = new StackedSwitcher(0);
(new RequestResetter(resetter_container(['account_switcher' => $empty]), []))->reset();
ok('an already-empty stack costs exactly one refused call', $empty->calls === 1);

$bottomless = new BottomlessSwitcher();
$log = (new RequestResetter(resetter_container(['account_switcher' => $bottomless]), []))->reset();
ok(
	'a switcher that never refuses is bounded rather than spun',
	$bottomless->calls === RequestResetter::MAX_SWITCHER_DEPTH,
);
ok(
	'and the bound is reported, so a stuck stack is not silent',
	($log['account_switcher'] ?? '') === 'bound reached',
);

echo "\n# the views page render array, held on a class static BY REFERENCE\n";

// views is not loaded in this harness, so the guard is what gets exercised here: the clear must
// report false rather than autoloading the class it came to reset
$log = (new RequestResetter(resetter_container([]), []))->reset();
ok(
	'it reports not-cleared when views was never loaded',
	($log['views_page_render_array_cleared'] ?? null) === false,
);
ok(
	'and does NOT autoload views to find that out',
	class_exists('Drupal\\views\\Plugin\\views\\display\\Page', false) === false,
);

/**
 * A stand-in carrying core's exact signature for the views page render array.
 *
 * Assigns whenever the argument is set, takes it by reference, and returns the previous value
 * otherwise -- which is what makes passing an empty array a clear rather than a no-op.
 */
final class FakeViewsPage
{
	/**
	 * The retained render array.
	 *
	 * @var array<string,mixed>|null
	 */
	protected static $pageRenderArray;

	/**
	 * Sets or reads the retained render array.
	 *
	 * @param array<string,mixed>|null $element
	 *   The array to retain, or null to read the current one.
	 *
	 * @return array<string,mixed>|null
	 *   The retained render array.
	 */
	public static function &setPageRenderArray(?array &$element = null)
	{
		if (isset($element)) {
			static::$pageRenderArray = &$element;
		}
		return static::$pageRenderArray;
	}
}

$pinned = ['#markup' => 'visitor A'];
FakeViewsPage::setPageRenderArray($pinned);
ok(
	'the control: a set array is retained across calls',
	(FakeViewsPage::setPageRenderArray()['#markup'] ?? null) === 'visitor A',
);
$empty = [];
FakeViewsPage::setPageRenderArray($empty);
ok(
	'passing an empty array clears it through core own API, no reflection',
	FakeViewsPage::setPageRenderArray() === [],
);
// the by-reference assignment is the reason this matters: without the clear, the NEXT visitor
// reading the getter is handed the previous one's array rather than an empty one
ok(
	'so the next reader cannot see the previous visitor markup',
	!isset(FakeViewsPage::setPageRenderArray()['#markup']),
);

echo "\n# the identity, which Drupal never returns to anonymous by itself\n";

// measured: after one admin login, a request carrying NO cookie came back "This route can only be
// accessed by anonymous users" with currentUser()->id() still 1
$proxy = new AccountProxy(new EventDispatcher());
$proxy->setAccount(new UserSession(['uid' => 5]));
$log = (new RequestResetter(resetter_container(['current_user' => $proxy]), []))->reset();
ok('it records the uid it found', ($log['identity']['before'] ?? null) === 5);
ok('and leaves the proxy anonymous', ($log['identity']['after'] ?? null) === 0);
ok('which the proxy itself agrees with', (int) $proxy->id() === 0);
// setInitialAccountId() raises once an account is set, and the kernel calls it on the next
// boot-from-session path, so the id property has to be cleared as well as the account
ok(
	'the id property is cleared, or the next boot-from-session path raises',
	(new ReflectionObject($proxy))->getProperty('id')->getValue($proxy) === 0,
);

$log = (new RequestResetter(resetter_container([]), []))->reset();
ok(
	'an uninitialized current_user is skipped rather than constructed',
	($log['identity']['skipped'] ?? null) === 'not initialized',
);

$log = (new RequestResetter(
	resetter_container(['current_user' => new BrokenAccount()]),
	[],
))->reset();
ok(
	'a proxy that raises is recorded rather than fatal',
	str_contains($log['identity']['error'] ?? '', 'not usable'),
);

echo "\n# the session copy in memory\n";

$_SESSION = ['uid' => 5, 'secret' => 'from the last visitor'];
$sessionSpy = new SessionSpy();
$log = (new RequestResetter(resetter_container(['session' => $sessionSpy]), []))->reset();
ok('$_SESSION is emptied, or the next visitor inherits it', $_SESSION === []);
ok('the started flag is cleared', $sessionSpy->started === false);
ok('and closed, and startedLazy', $sessionSpy->closed === false && !$sessionSpy->startedLazy);
ok(
	'each cleared flag is named, so a renamed property is visible as a gap',
	$log['session']['storage'] === ['session.started', 'session.closed', 'session.startedLazy'],
);
ok('and the extension is reported present', ($log['session']['ext'] ?? null) === true);

$log = (new RequestResetter(
	resetter_container(['session_manager' => new FrozenSession()]),
	[],
))->reset();
ok(
	'a flag that cannot be written is recorded against its id',
	isset($log['session']['errors']['session_manager']),
);

echo "\n# PageCache's memoized cache id, which a persistent kernel serves stale from\n";

$page = new PageCache();
$kernel = new MiddlewareLink(static fn(): object => new MiddlewareLink($page));
$log = (new RequestResetter(resetter_container(['http_kernel' => $kernel]), []))->reset();
ok('it finds PageCache through a closure-wrapped chain', $log['page_cache_cid_cleared'] === 1);
ok('and clears the memoized id', $page->cid === null);

// zero means the chain shape changed, and a caller is told to treat that as a failure
$log = (new RequestResetter(
	resetter_container(['http_kernel' => new MiddlewareLink()]),
	[],
))->reset();
ok('a chain with no PageCache in it reports zero', $log['page_cache_cid_cleared'] === 0);

$log = (new RequestResetter(resetter_container([]), []))->reset();
ok(
	'an uninitialized http_kernel reports zero rather than building one',
	$log['page_cache_cid_cleared'] === 0,
);

$cycle = new MiddlewareLink();
$cycle->next = $cycle;
$log = (new RequestResetter(resetter_container(['http_kernel' => $cycle]), []))->reset();
ok('a cyclic chain terminates rather than recursing forever', $log['page_cache_cid_cleared'] === 0);

$log = (new RequestResetter(
	resetter_container(['http_kernel' => new ThrowingLink()]),
	[],
))->reset();
ok('a closure that raises is stepped over', $log['page_cache_cid_cleared'] === 0);

// the kernel owns the container, so descending into it would walk the whole service graph
$hidden = new PageCache();
$realKernel = (new ReflectionClass(DrupalKernel::class))->newInstanceWithoutConstructor();
(new ReflectionObject($realKernel))->getProperty('container')->setValue($realKernel, $hidden);
$log = (new RequestResetter(
	resetter_container(['http_kernel' => new MiddlewareLink(null, $realKernel)]),
	[],
))->reset();
ok('it never descends into a DrupalKernel', $log['page_cache_cid_cleared'] === 0);
ok('so a PageCache reachable only through one is left alone', $hidden->cid !== null);

echo "\n# FormState::\$anyErrors, the class static that disabled every submit handler\n";

// setErrorByName() sets it and FormBuilder::processForm() runs submit handlers only when it is
// false. Core clears it from submitForm(), the PROGRAMMATIC path, so a normal HTTP request never
// does -- one mistyped password disabled login for the whole object
(new ReflectionProperty(FormState::class, 'anyErrors'))->setValue(null, true);
ok(
	'CONTROL: the static really is set, so the assertion below is not vacuous',
	FormState::hasAnyErrors(),
);
$log = (new RequestResetter(resetter_container([]), []))->reset();
ok('reset() reports the clear', ($log['form_errors_reset'] ?? null) === true);
// asserted on the STATIC and not on the log value, or a method that does nothing passes
ok('and FormState agrees the errors are gone', FormState::hasAnyErrors() === false);

// the failure path needs a Drupal whose FormState has no `anyErrors`, which cannot coexist with
// the real class in one process, so it runs in a child. Failing quietly is the wanted behaviour:
// a runtime that renamed the property should lose this reset, not refuse to serve
$childOut = [];
$childStatus = 0;
exec(
	escapeshellarg(PHP_BINARY) .
		' ' .
		escapeshellarg(__DIR__ . '/fixtures/renamed-form-state.php') .
		' 2>&1',
	$childOut,
	$childStatus,
);
$childText = implode("\n", $childOut);
ok('a renamed FormState property loses the reset, not the request', $childStatus === 0, $childText);
ok(
	'and reset() reports form_errors_reset false',
	str_contains($childText, 'form_errors_reset=false'),
	$childText,
);
ok(
	'while every other step still ran',
	str_contains($childText, 'html_seen_ids_reset=true') &&
		str_contains($childText, 'drupal_static_reset=true'),
	$childText,
);

echo "\n# the statics, and the diagnostic that proves the reset happened\n";

$log = (new RequestResetter(resetter_container([]), []))->reset();
ok('drupal_static_reset() is called and reported', ($log['drupal_static_reset'] ?? null) === true);
ok('Html::resetSeenIds() is called and reported', ($log['html_seen_ids_reset'] ?? null) === true);

// a second visitor to a page gets `--2` ids without this, measured on a live object
Html::getUniqueId('block-title');
$suffixed = Html::getUniqueId('block-title');
ok('CONTROL: a repeat id really is suffixed within one request', $suffixed !== 'block-title');
(new RequestResetter(resetter_container([]), []))->reset();
ok(
	'and the registry starts clean for the next one',
	Html::getUniqueId('block-title') === 'block-title',
);

$probe = &drupal_static('cfw_leak_probe');
$probe = 'set-by-request-1';
ok(
	'CONTROL: drupal_static holds the value within a request',
	drupal_static('cfw_leak_probe') === 'set-by-request-1',
);
(new RequestResetter(resetter_container([]), []))->reset();
ok(
	'and the reset drops it, which is the disclosure case',
	drupal_static('cfw_leak_probe') === null,
);

// verify() reads the SAME global container the rest of the request reads, on purpose: injecting
// the service would observe a different object and pass while the bug remained
Drupal::setContainer(
	resetter_container(['current_user' => new AccountProxy(new EventDispatcher())]),
);
$verified = (new RequestResetter(resetter_container([]), []))->verify();
ok('verify() reports the live uid', ($verified['current_uid'] ?? null) === 0);
ok('and the static probe it was asked about', array_key_exists('static_probe', $verified));

Drupal::unsetContainer();
$verified = (new RequestResetter(resetter_container([]), []))->verify();
ok(
	'verify() reports ERR rather than raising with no container',
	$verified['current_uid'] === 'ERR',
);
// #endregion
// #region the host bridge, installed HERE and deliberately not at file scope
//
// Every assertion ABOVE this line measures a capability in its ABSENT state, which is the control
// the runtime cannot provide. A vrzno_env() hoisted to the top of the file would silently turn
// all of them into the present case, so it is declared at the point the present case starts being
// the subject. The declaration is conditional, which binds it at runtime rather than at compile
// time -- the same reason zlib-fix.ts needs no eval.
echo "\n# the host bridge, and the control that it was absent until now\n";

ok('CONTROL: no bridge was installed for anything above', !function_exists('vrzno_env'));
ok('CONTROL: so Host answered NULL', Host::fn('cfwLog') === null);

if (!function_exists('vrzno_env')) {
	/**
	 * Stands in for the vrzno extension, which resolves a key on the PHP Module.
	 *
	 * @param string $name
	 *   The Module key the runtime would have installed.
	 *
	 * @return mixed
	 *   Whatever the suite hung there, or NULL.
	 */
	function vrzno_env(string $name): mixed
	{
		return $GLOBALS['cfw_test_host'][$name] ?? null;
	}
}

/**
 * Installs a set of host functions for the assertions that follow.
 *
 * @param array $host
 *   Module key to invokable, or to a plain value for the flags the runtime sets.
 */
function install_host(array $host): void
{
	$GLOBALS['cfw_test_host'] = $host;
}

/**
 * A host function that records its payload and answers a canned reply.
 */
class HostSpy
{
	/**
	 * Every decoded payload it was handed.
	 *
	 * @var array
	 */
	public $calls = [];

	/**
	 * Builds a spy with a canned reply.
	 *
	 * @param array $reply
	 *   What to answer with.
	 */
	public function __construct(private array $reply = ['ok' => true]) {}

	/**
	 * Records the payload and answers.
	 *
	 * @param string $json
	 *   The encoded request.
	 *
	 * @return string
	 *   The encoded reply.
	 */
	public function __invoke(string $json): string
	{
		$this->calls[] = json_decode($json, true);
		return (string) json_encode($this->reply);
	}
}

install_host(['cfwLog' => new HostSpy()]);
ok('the bridge answers once installed', Host::fn('cfwLog') !== null);
ok('and has() agrees', Host::has('cfwLog'));
ok('a key the host did not install is still absent', Host::fn('cfwNothing') === null);
// a non-invokable value is not a capability: vrzno surfaces flags through the same call
install_host(['cfwFlag' => true]);
ok('a plain flag is not mistaken for a capability', Host::fn('cfwFlag') === null);
// #endregion
// #region Host::call, over a bridge that answers
echo "\n# Host::call, which is the only seam between PHP and the runtime\n";

$spy = new HostSpy(['ok' => true, 'id' => 'abc']);
install_host(['cfwThing' => $spy]);
$reply = Host::call('cfwThing', ['to' => 'nobody@example.com']);
ok('it decodes the reply', ($reply['ok'] ?? null) === true && ($reply['id'] ?? '') === 'abc');
ok('and hands the payload across as JSON', ($spy->calls[0]['to'] ?? '') === 'nobody@example.com');

install_host([
	'cfwThrows' => static function (string $json): string {
		throw new RuntimeException('the host call blew up');
	},
]);
$reply = Host::call('cfwThrows', []);
ok('a host that raises is reported, not propagated', ($reply['ok'] ?? null) === false);
ok('and the class is named', str_contains($reply['error'] ?? '', 'RuntimeException'));

install_host(['cfwWrongType' => static fn(string $json): int => 7]);
$reply = Host::call('cfwWrongType', []);
ok('a non-string reply is refused', ($reply['ok'] ?? null) === false);
ok('and the type it got is named', str_contains($reply['error'] ?? '', 'int'));

install_host(['cfwNotJson' => static fn(string $json): string => 'not json at all']);
$reply = Host::call('cfwNotJson', []);
ok('a reply that is not a JSON object is refused', ($reply['ok'] ?? null) === false);
ok('and the first bytes are quoted back', str_contains($reply['error'] ?? '', 'not json at all'));

// a payload PHP cannot encode has to fail here rather than crossing as "null"
install_host(['cfwEncodable' => new HostSpy()]);
$reply = Host::call('cfwEncodable', ['bad' => NAN]);
ok('an unencodable payload is refused before it crosses', ($reply['ok'] ?? null) === false);
ok('and json_last_error is quoted', str_contains($reply['error'] ?? '', 'could not encode'));
// #endregion
// #region the logger
echo "\n# the logger, which ships Drupal's log to the runtime\n";

/**
 * The placeholder parser core hands the logger, reduced to the one method it calls.
 */
class ParserSpy implements LogMessageParserInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function parseMessagePlaceholders(&$message, array &$context)
	{
		$out = [];
		foreach ($context as $key => $value) {
			if (str_starts_with((string) $key, '@')) {
				$out[$key] = (string) $value;
			}
		}
		return $out;
	}
}

$logSpy = new HostSpy();
install_host(['cfwLog' => $logSpy]);
(new CfwLogger(new ParserSpy()))->log(3, 'Mail to @to failed', [
	'@to' => 'a@example.com',
	'channel' => 'drupflare',
	'uid' => 7,
]);
$sent = $logSpy->calls[0] ?? [];
// a log line an operator has to reassemble is not observability
ok(
	'placeholders are resolved before the line ships',
	($sent['message'] ?? '') === 'Mail to a@example.com failed',
);
ok('severity 3 maps to the error level tail filters on', ($sent['level'] ?? '') === 'error');
ok('the channel crosses', ($sent['channel'] ?? '') === 'drupflare');
ok('and the uid, as an int', ($sent['uid'] ?? null) === 7);
// timestamps exceed 2^31 on a 32-bit build, so they cross as a string rather than wrapping
ok('the timestamp crosses as a string', is_string($sent['timestamp'] ?? null));

$logSpy = new HostSpy();
install_host(['cfwLog' => $logSpy]);
(new CfwLogger(new ParserSpy()))->log(7, 'plain message', []);
$sent = $logSpy->calls[0] ?? [];
ok('a message with no placeholders is shipped as-is', ($sent['message'] ?? '') === 'plain message');
ok('severity 7 maps to debug', ($sent['level'] ?? '') === 'debug');

$logSpy = new HostSpy();
install_host(['cfwLog' => $logSpy]);
(new CfwLogger(new ParserSpy()))->log('notice', 'a PSR-3 level name', []);
ok(
	'a non-integer level falls back to info rather than dropping the line',
	($logSpy->calls[0]['level'] ?? '') === 'info',
);

$logSpy = new HostSpy();
install_host(['cfwLog' => $logSpy]);
(new CfwLogger(new ParserSpy()))->log(2, 'boom', ['exception' => new RuntimeException('kaboom')]);
$sent = $logSpy->calls[0] ?? [];
ok('an exception is flattened onto the payload', str_contains($sent['exception'] ?? '', 'kaboom'));
ok('with a trace an operator can read', ($sent['trace'] ?? '') !== '');

install_host([
	'cfwLog' => static function (string $json): string {
		throw new RuntimeException('the tail consumer is gone');
	},
]);
// a logger that throws turns a warning into an outage
$threw = false;
try {
	(new CfwLogger(new ParserSpy()))->log(3, 'still fine', []);
} catch (Throwable $e) {
	$threw = true;
}
ok('a host that raises does not take the request with it', !$threw);

install_host([]);
$threw = false;
try {
	(new CfwLogger(new ParserSpy()))->log(3, 'no bridge here', []);
} catch (Throwable $e) {
	$threw = true;
}
ok('and neither does no bridge at all', !$threw);

// installFatalHandler() covers what Drupal's own handlers do not: a fatal during bootstrap and a
// shutdown after an uncaught error. The shutdown BODY only runs at process end, after the
// coverage driver has stopped, so what is assertable here is the guard and that it disturbs
// nothing
install_host([]);
$threw = false;
try {
	CfwLogger::installFatalHandler();
} catch (Throwable $e) {
	$threw = true;
}
ok('installFatalHandler returns quietly when there is no capability', !$threw);

$afterSpy = new HostSpy();
install_host(['cfwLog' => $afterSpy]);
CfwLogger::installFatalHandler();
(new CfwLogger(new ParserSpy()))->log(3, 'after the handler', []);
ok('and installing it leaves ordinary logging working', count($afterSpy->calls) === 1);
// #endregion
// #region the health ledger
echo "\n# the health ledger, which must not write through the layer it reports on\n";

$ledgerSpy = new HostSpy();
install_host(['cfwHealth' => $ledgerSpy]);
$leak = new Finding('db.txn_leaked', Finding::ERROR, 'scope', 'depth 2');
ok('a finding is accepted', HealthLedger::record($leak, 'rollback', 'cleared', 2));
$written = $ledgerSpy->calls[0] ?? [];
ok('the ladder rung crosses', ($written['action'] ?? '') === 'rollback');
ok('and the outcome', ($written['outcome'] ?? '') === 'cleared');
ok('and the attempt, which the circuit breaker counts', ($written['attempt'] ?? null) === 2);
ok('and the finding itself', ($written['code'] ?? '') === 'db.txn_leaked');

install_host(['cfwHealth' => new HostSpy(['ok' => false, 'error' => 'table missing'])]);
ok('a refused entry reports false rather than pretending', !HealthLedger::record($leak));

install_host([]);
ok('and no capability at all is false too', !HealthLedger::record($leak));

$ledgerSpy = new HostSpy();
install_host(['cfwHealth' => $ledgerSpy]);
ok('recordAll counts what the host accepted', HealthLedger::recordAll([$leak, $leak, $leak]) === 3);
install_host([]);
ok('and counts zero when nothing can be written', HealthLedger::recordAll([$leak]) === 0);
// #endregion
// #region the service provider, driven over a real container
echo "\n# the service provider, which is what makes the swaps survive a rebuild\n";

/**
 * A container carrying the definitions the provider looks for.
 *
 * @return ContainerBuilder
 *   Definitions with core's own classes, because every swap is guarded on the class and a site
 *   that already overrode one keeps its own.
 */
function core_container(): ContainerBuilder
{
	$container = new ContainerBuilder();
	$container->setDefinition('router.dumper', new Definition(MatcherDumper::class));
	$container->setDefinition(
		'cache.backend.database',
		new Definition(DatabaseBackendFactory::class),
	);
	$container->setDefinition(
		'lock',
		(new Definition(DatabaseLockBackend::class))->setArguments(['@database'])->setLazy(true),
	);
	$container->setDefinition(
		'lock.persistent',
		(new Definition(PersistentDatabaseLockBackend::class))->setArguments(['@database']),
	);
	$container->setDefinition('http_handler_stack', new Definition(HandlerStack::class));
	return $container;
}

install_host([]);
$container = core_container();
(new DrupflareServiceProvider())->register($container);

ok(
	'router.dumper becomes the fingerprinting subclass',
	$container->getDefinition('router.dumper')->getClass() === CfwMatcherDumper::class,
);
// core marks it lazy, and a lazy service resolves through a generated proxy class that only
// exists for the class core named
ok('and stops being lazy', $container->getDefinition('router.dumper')->isLazy() === false);
ok(
	'the cache factory becomes the one that drops unread bin indexes',
	$container->getDefinition('cache.backend.database')->getClass() ===
		CfwCacheBackendFactory::class,
);
ok(
	'lock becomes the backend that needs no clock',
	$container->getDefinition('lock')->getClass() === CfwLockBackend::class,
);
ok(
	'and so does lock.persistent, whose rows outlive a request',
	$container->getDefinition('lock.persistent')->getClass() === CfwLockBackend::class,
);
// core passes '@database'; this backend takes nothing, and a leftover argument is a fatal
ok('the core argument is cleared', $container->getDefinition('lock')->getArguments() === []);
ok('and the lazy flag with it', $container->getDefinition('lock')->isLazy() === false);

// FetchHandler is guarded: on an ASYNCIFY=0, non-JSPI build it is a guaranteed
// "ReferenceError: Asyncify is not defined" the first time anything calls httpClient().
// Leaving core's StreamHandler in its place was NOT the safe fallback it read as -- it opens the
// URL through the https wrapper, cannot read $http_response_header back out of a userland
// wrapper, and rejects every call with the body already fetched
//
// hasDefinition() FIRST, and it is not defensive: removing the fix leaves no
// `drupflare.fetch_handler` at all, and `ContainerBuilder::getDefinition()` THROWS on a missing id.
// Falsified by reverting the provider -- without this guard the suite dies on an uncaught
// ServiceNotFoundException instead of naming which assertion moved
$installed = $container->hasDefinition('drupflare.fetch_handler');
ok('a handler is installed even when the runtime cannot suspend', $installed);
ok(
	'the SUSPENDING one is not, because it would ReferenceError on the first call',
	$installed &&
		$container->getDefinition('drupflare.fetch_handler')->getClass() !== FetchHandler::class,
);
ok(
	'the cached handler takes its place instead of core StreamHandler',
	$installed &&
		$container->getDefinition('drupflare.fetch_handler')->getClass() ===
			CachedFetchHandler::class,
);
ok(
	'and the handler stack is pointed at it',
	count($container->getDefinition('http_handler_stack')->getArguments()) === 1,
);
ok(
	'the resetter is registered either way',
	$container->getDefinition('drupflare.request_resetter')->getClass() === RequestResetter::class,
);
ok(
	'and it is public, because the host reaches it by id between requests',
	$container->getDefinition('drupflare.request_resetter')->isPublic(),
);

install_host(['cfwCanSuspend' => true]);
$container = core_container();
(new DrupflareServiceProvider())->register($container);
ok(
	'a build that CAN suspend gets the fetch handler',
	$container->getDefinition('drupflare.fetch_handler')->getClass() === FetchHandler::class,
);
ok(
	'and the handler stack is pointed at it',
	count($container->getDefinition('http_handler_stack')->getArguments()) === 1,
);
ok(
	'the handler stays private, because only the stack resolves it',
	!$container->getDefinition('drupflare.fetch_handler')->isPublic(),
);
install_host(['cfwCanSuspend' => 'yes']);
$container = core_container();
(new DrupflareServiceProvider())->register($container);
ok(
	'a non-boolean flag is not read as consent to install the suspending one',
	$container->hasDefinition('drupflare.fetch_handler') &&
		$container->getDefinition('drupflare.fetch_handler')->getClass() ===
			CachedFetchHandler::class,
);
install_host([]);

// a site that already overrode any of these keeps its own
$container = core_container();
$container->getDefinition('router.dumper')->setClass(ResettableSpy::class);
$container->getDefinition('cache.backend.database')->setClass(ResettableSpy::class);
$container->getDefinition('lock')->setClass(ResettableSpy::class);
(new DrupflareServiceProvider())->register($container);
ok(
	'an already-overridden router.dumper is left alone',
	$container->getDefinition('router.dumper')->getClass() === ResettableSpy::class,
);
ok(
	'an already-overridden cache factory is left alone',
	$container->getDefinition('cache.backend.database')->getClass() === ResettableSpy::class,
);
ok(
	'an already-overridden lock is left alone',
	$container->getDefinition('lock')->getClass() === ResettableSpy::class,
);

// a container missing every definition must not fatal: the provider runs on every build,
// including ones assembled before this module is on the list
$bare = new ContainerBuilder();
(new DrupflareServiceProvider())->register($bare);
ok(
	'a container with no http_handler_stack returns before registering the resetter',
	!$bare->hasDefinition('drupflare.request_resetter'),
);
ok('and nothing else was invented', !$bare->hasDefinition('router.dumper'));

// the seed list is filtered against what the container actually has, and a tagged service is
// added on top of it
$container = core_container();
$container->setDefinition('current_user', new Definition(NotResettable::class));
$container->setDefinition('cache.static', new Definition(NotResettable::class));
$container->setDefinition(
	'my_module.thing',
	(new Definition(NotResettable::class))->addTag('drupflare.reset'),
);
(new DrupflareServiceProvider())->register($container);
$resettable = $container->getDefinition('drupflare.request_resetter')->getArgument(1);
ok(
	'the seed list is filtered to services the container has, plus anything tagged',
	$resettable === ['current_user', 'cache.static', 'my_module.thing'],
	implode(',', $resettable),
);
// #endregion
// #region the durable file stream wrapper
echo "\n# public:// and private://, backed by the Durable Object's own SQL\n";

/**
 * A host file store: a flat keyspace, which is what the runtime actually implements.
 */
class FileHost
{
	/**
	 * Uri to bytes.
	 *
	 * @var array
	 */
	public $files = [];

	/**
	 * Every (operation, request) pair the wrapper made.
	 *
	 * @var array
	 */
	public $calls = [];

	/**
	 * Whether a write should be refused, for the torn-state assertion.
	 *
	 * @var bool
	 */
	public $refuseWrites = false;

	/**
	 * Hangs every file capability on the bridge.
	 */
	public function install(): void
	{
		install_host([
			'cfwFileRead' => $this->handler('read'),
			'cfwFileWrite' => $this->handler('write'),
			'cfwFileStat' => $this->handler('stat'),
			'cfwFileList' => $this->handler('list'),
			'cfwFileDelete' => $this->handler('delete'),
			'cfwFileRename' => $this->handler('rename'),
		]);
	}

	/**
	 * One capability, as the runtime would install it.
	 *
	 * @param string $op
	 *   Which operation this key serves.
	 *
	 * @return Closure
	 *   The invokable the bridge hands back.
	 */
	private function handler(string $op): Closure
	{
		return function (string $json) use ($op): string {
			$request = json_decode($json, true) ?: [];
			$this->calls[] = [$op, $request];
			return (string) json_encode($this->dispatch($op, $request));
		};
	}

	/**
	 * Answers one operation.
	 *
	 * @param string $op
	 *   Which operation.
	 * @param array $request
	 *   The decoded payload.
	 *
	 * @return array
	 *   The reply, always with an 'ok' key.
	 */
	private function dispatch(string $op, array $request): array
	{
		$uri = (string) ($request['uri'] ?? '');
		switch ($op) {
			case 'read':
				return isset($this->files[$uri])
					? ['ok' => true, 'b64' => base64_encode($this->files[$uri])]
					: ['ok' => false];

			case 'write':
				if ($this->refuseWrites) {
					return ['ok' => false, 'error' => 'quota'];
				}
				$this->files[$uri] = (string) base64_decode((string) ($request['b64'] ?? ''), true);
				return ['ok' => true];

			case 'stat':
				return isset($this->files[$uri])
					? [
						'ok' => true,
						'size' => strlen($this->files[$uri]),
						'modified' => 1780000000000,
					]
					: ['ok' => false];

			case 'list':
				$prefix = (string) ($request['prefix'] ?? '');
				$found = [];
				foreach (array_keys($this->files) as $key) {
					if (str_starts_with((string) $key, $prefix)) {
						$found[] = ['uri' => $key];
					}
				}
				if (isset($request['limit'])) {
					$found = array_slice($found, 0, (int) $request['limit']);
				}
				return ['ok' => true, 'files' => $found];

			case 'delete':
				unset($this->files[$uri]);
				return ['ok' => true];

			case 'rename':
				$from = (string) ($request['from'] ?? '');
				$to = (string) ($request['to'] ?? '');
				if (!isset($this->files[$from])) {
					return ['ok' => false];
				}
				$this->files[$to] = $this->files[$from];
				unset($this->files[$from]);
				return ['ok' => true];
		}
		return ['ok' => false];
	}
}

install_host([]);
ok(
	'the wrapper reports itself unavailable with no file capability',
	CfwFileStreamWrapper::available() === false,
);

$fileHost = new FileHost();
$fileHost->install();
ok('and available once the host installs both halves', CfwFileStreamWrapper::available());

ok(
	'register() claims both core schemes',
	CfwFileStreamWrapper::register() === ['public', 'private'],
);
ok('and PHP resolves them', in_array('public', stream_get_wrappers(), true));
// registerWrapper() unregisters a scheme before re-registering it, so a second claim has to work
// rather than failing on EEXIST
ok('registering twice is not an error', CfwFileStreamWrapper::register() === ['public', 'private']);

ok('a write lands in the store', file_put_contents('public://a.txt', 'hello') === 5);
$lastCall = $fileHost->calls[array_key_last($fileHost->calls)];
ok('the host was told the mime type', ($lastCall[1]['mime'] ?? '') === 'text/plain');
ok('and reads back', file_get_contents('public://a.txt') === 'hello');

file_put_contents('public://i.png', 'p');
$lastCall = $fileHost->calls[array_key_last($fileHost->calls)];
ok('an image extension maps too', ($lastCall[1]['mime'] ?? '') === 'image/png');
file_put_contents('public://thing.unknownext', 'p');
$lastCall = $fileHost->calls[array_key_last($fileHost->calls)];
ok(
	'and an extension it does not know crosses as null rather than a guess',
	array_key_exists('mime', $lastCall[1]) && $lastCall[1]['mime'] === null,
);

clearstatcache();
ok('file_exists() answers through url_stat', file_exists('public://a.txt'));
ok('and filesize() reports the stored length', filesize('public://a.txt') === 5);
clearstatcache();
ok('a path nothing wrote does not exist', !file_exists('public://nope.txt'));

// 'r' on an absent file is a failure; 'a' and 'w' create it
ok('fopen r on an absent file fails', @fopen('public://missing.txt', 'r') === false);
$handle = fopen('public://created.txt', 'w');
ok('fopen w creates a handle', is_resource($handle));
fclose($handle);
// a truncating open is itself a change, so fopen('w') plus fclose() with no write must still
// produce an empty file rather than leaving the old bytes
ok(
	'and an empty file, even with nothing written',
	($fileHost->files['public://created.txt'] ?? null) === '',
);

file_put_contents('public://append.txt', 'one');
$handle = fopen('public://append.txt', 'a');
fwrite($handle, '-two');
fclose($handle);
ok('append starts at the end', $fileHost->files['public://append.txt'] === 'one-two');

$handle = fopen('public://a.txt', 'r');
ok('a read-only handle refuses a write', fwrite($handle, 'x') === 0);
ok('and the bytes are unchanged', $fileHost->files['public://a.txt'] === 'hello');
ok('ftell reports the cursor', ftell($handle) === 0);
ok('fread advances it', fread($handle, 2) === 'he' && ftell($handle) === 2);
ok('fseek moves it', fseek($handle, 1, SEEK_CUR) === 0 && ftell($handle) === 3);
ok(
	'SEEK_END is relative to the length',
	fseek($handle, -1, SEEK_END) === 0 && ftell($handle) === 4,
);
ok('a negative target is refused', fseek($handle, -100, SEEK_SET) === -1);
// a seek past the end is legal on a writable handle and out of range on a read-only one
ok('and so is a seek past the end on a read-only handle', fseek($handle, 500, SEEK_SET) === -1);
fseek($handle, 0, SEEK_SET);
ok('feof is false mid-file', !feof($handle));
fread($handle, 100);
ok('and true once every byte is read', feof($handle));
fclose($handle);

$handle = fopen('public://sparse.txt', 'w+');
fseek($handle, 3, SEEK_SET);
fwrite($handle, 'end');
fclose($handle);
ok(
	'a sparse write pads with NULs rather than losing the offset',
	$fileHost->files['public://sparse.txt'] === "\0\0\0end",
);

$handle = fopen('public://a.txt', 'r+');
ok('ftruncate shortens', ftruncate($handle, 2));
fclose($handle);
ok('and the store agrees', $fileHost->files['public://a.txt'] === 'he');

// the return value is load-bearing: fflush() and fclose() both surface it, and reporting TRUE for
// a write that did not land is exactly the torn state this wrapper exists to avoid
$fileHost->refuseWrites = true;
$handle = fopen('public://refused.txt', 'w');
fwrite($handle, 'nope');
ok('a refused flush reports false rather than a silent loss', fflush($handle) === false);
fclose($handle);
$fileHost->refuseWrites = false;

ok(
	'unlink removes the row',
	unlink('public://append.txt') && !isset($fileHost->files['public://append.txt']),
);
file_put_contents('public://from.txt', 'moved');
ok('rename moves it', rename('public://from.txt', 'public://to.txt'));
ok('and the bytes went with it', ($fileHost->files['public://to.txt'] ?? '') === 'moved');
ok('renaming something absent reports false', !@rename('public://ghost.txt', 'public://x.txt'));

// storage is a flat keyspace with no directory records, so a directory exists exactly when it has
// contents -- the same model an object store uses, and what file_prepare_directory() needs
ok('mkdir succeeds, or file_prepare_directory refuses the whole write', mkdir('public://styles'));
file_put_contents('public://styles/thumb/one.png', 'p');
file_put_contents('public://styles/two.png', 'q');
clearstatcache();
ok('a prefix with contents under it stats as a directory', is_dir('public://styles'));
clearstatcache();
ok('and one with nothing under it does not', !is_dir('public://empty-dir'));

$entries = array_values(array_diff((array) scandir('public://styles'), ['.', '..']));
sort($entries);
ok(
	'readdir reports the immediate children only',
	$entries === ['thumb', 'two.png'],
	implode(',', $entries),
);

$dir = opendir('public://styles');
$first = readdir($dir);
rewinddir($dir);
ok('rewinddir restarts the listing', readdir($dir) === $first);
closedir($dir);

ok('rmdir removes what is under the prefix', rmdir('public://styles'));
ok('and the rows are gone', !isset($fileHost->files['public://styles/two.png']));

$wrapper = new CfwFileStreamWrapper();
$wrapper->setUri('private://secret.txt');
ok('setUri picks the scheme up from the uri', $wrapper->getName() === 'Durable private files');
ok('and getUri hands it back', $wrapper->getUri() === 'private://secret.txt');
ok(
	'a private uri gets the system route',
	$wrapper->getExternalUrl() === '/system/files/secret.txt',
);
ok(
	'the directory path matches core, so image style routes are unchanged',
	$wrapper->getDirectoryPath() === 'system/files',
);
// parse_url() CANNOT split these: for public://styles/thumb/a.png it reports the host as
// `styles`, which silently loses a directory from the external URL
$wrapper->setUri('public://styles/thumb/a.png');
ok(
	'the external url keeps every directory',
	$wrapper->getExternalUrl() === '/sites/default/files/styles/thumb/a.png',
);
ok('and the public directory path too', $wrapper->getDirectoryPath() === 'sites/default/files');
ok(
	'dirname of a nested uri is its parent',
	$wrapper->dirname('public://a/b/c.png') === 'public://a/b',
);
// dirname() answers '.' for a bare filename, which is not a uri Drupal can use
ok(
	'dirname of a bare filename is the scheme root',
	$wrapper->dirname('public://c.png') === 'public://',
);
ok(
	'a uri with no scheme falls back to the instance scheme',
	$wrapper->dirname('c.png') === 'public://',
);
$other = new CfwFileStreamWrapper();
$other->setUri('temporary://x.txt');
ok(
	'setUri ignores a scheme this wrapper does not own',
	$other->getName() === 'Durable public files',
);

// LOCAL is deliberately not set: it promises realpath() returns a usable filesystem path
ok(
	'the type is NORMAL, not LOCAL',
	CfwFileStreamWrapper::getType() === StreamWrapperInterface::NORMAL,
);
ok('there is a description', $wrapper->getDescription() !== '');
// there is no path on any filesystem that holds these bytes
ok('realpath is FALSE rather than something plausible', $wrapper->realpath() === false);
ok(
	'metadata calls are refused rather than silently accepted',
	$wrapper->stream_metadata('public://a', 1, 0) === false,
);
ok('there is no locking to report', $wrapper->stream_lock(LOCK_EX) === false);
ok('and no descriptor for stream_select', $wrapper->stream_cast(STREAM_CAST_FOR_SELECT) === false);
ok('stream_set_option is refused too', $wrapper->stream_set_option(1, 0, 0) === false);

stream_wrapper_unregister('public');
stream_wrapper_unregister('private');
install_host([]);
// #endregion
// #region the mail plugin
echo "\n# the mail plugin, which replaces every SMTP transport with a binding\n";

/**
 * A logger channel that records instead of writing, so a mail failure is assertable.
 */
class LoggerChannelSpy
{
	/**
	 * Every (message, context) pair it was handed.
	 *
	 * @var array
	 */
	public $errors = [];

	/**
	 * Records one error.
	 *
	 * @param string $message
	 *   The message template.
	 * @param array $context
	 *   Its placeholders.
	 */
	public function error(string $message, array $context = []): void
	{
		$this->errors[] = [$message, $context];
	}
}

/**
 * The `logger.factory` service, reduced to the one method Drupal::logger() calls.
 */
class LoggerFactorySpy
{
	/**
	 * Builds a factory over one recording channel.
	 *
	 * @param LoggerChannelSpy $channel
	 *   The channel every get() hands back.
	 */
	public function __construct(private LoggerChannelSpy $channel) {}

	/**
	 * Returns the recording channel.
	 *
	 * @param string $name
	 *   The channel name, ignored here.
	 *
	 * @return LoggerChannelSpy
	 *   The channel.
	 */
	public function get(string $name): LoggerChannelSpy
	{
		return $this->channel;
	}
}

/**
 * Puts a recording logger behind Drupal::logger() and hands the channel back.
 *
 * @return LoggerChannelSpy
 *   The channel every Drupal::logger() call will resolve to.
 */
function install_logger(): LoggerChannelSpy
{
	$channel = new LoggerChannelSpy();
	Drupal::setContainer(resetter_container(['logger.factory' => new LoggerFactorySpy($channel)]));
	return $channel;
}

$mail = new CfwMail();

// format() is core's behaviour on purpose: converting HTML to text and wrapping lines is
// Drupal's business, not the transport's
$formatted = $mail->format(['body' => ['first', 'second'], 'params' => []]);
ok('format() joins the body parts', str_contains($formatted['body'], "first\n\nsecond"));
ok('and leaves no html part for a text mail', !isset($formatted['html']));

// MailFormatHelper::htmlToText() reads the globals a bootstrapped Drupal sets
$GLOBALS['base_path'] = '/';
$GLOBALS['base_url'] = 'http://localhost';
$html = $mail->format(['body' => ['<p>hello</p>'], 'params' => ['html' => true]]);
ok('format() keeps the html body', ($html['html'] ?? '') === '<p>hello</p>');
ok('and converts the text body from it', trim($html['body']) === 'hello');
$scalar = $mail->format(['body' => 'a single string', 'params' => []]);
ok('a non-array body is accepted rather than fatal', str_contains($scalar['body'], 'a single'));

$channel = install_logger();
install_host([]);
// returning FALSE makes Drupal log a mail failure, which is the truthful outcome; throwing
// would break whatever content operation triggered it
ok(
	'mail() refuses with no binding rather than throwing',
	$mail->mail(['to' => 'a@example.com']) === false,
);
ok('and says so on the drupflare channel', count($channel->errors) === 1);
ok(
	'naming the recipient, so the failure is actionable',
	($channel->errors[0][1]['@to'] ?? '') === 'a@example.com',
);

$mailSpy = new HostSpy();
install_host(['cfwMail' => $mailSpy]);
$channel = install_logger();
$sent = $mail->mail([
	'to' => 'to@example.com',
	'subject' => 'Subject Line',
	'body' => 'the text part',
	'html' => '<p>the html part</p>',
	'headers' => [
		'From' => 'from@example.com',
		'Reply-to' => 'reply@example.com',
		'Cc' => 'cc@example.com',
		'X-Made-Up' => 'nope',
	],
]);
$payload = $mailSpy->calls[0] ?? [];
ok('mail() reports success when the binding accepts', $sent === true);
ok('and logs nothing on success', $channel->errors === []);
ok('the recipient crosses', ($payload['to'] ?? '') === 'to@example.com');
ok('the From header becomes the from field', ($payload['from'] ?? '') === 'from@example.com');
ok('Reply-to becomes replyTo', ($payload['replyTo'] ?? '') === 'reply@example.com');
ok('the subject crosses', ($payload['subject'] ?? '') === 'Subject Line');
ok('the body becomes the text part', ($payload['text'] ?? '') === 'the text part');
ok('and an html part crosses beside it', ($payload['html'] ?? '') === '<p>the html part</p>');
// the binding rejects unknown headers, so only the four it names are passed
ok(
	'a header the binding knows is forwarded',
	($payload['headers']['Cc'] ?? '') === 'cc@example.com',
);
ok(
	'and one it does not is dropped rather than rejected as a batch',
	!array_key_exists('X-Made-Up', $payload['headers'] ?? []),
);

$mailSpy = new HostSpy();
install_host(['cfwMail' => $mailSpy]);
$mail->mail(['to' => 'x@example.com', 'from' => 'fallback@example.com']);
$payload = $mailSpy->calls[0] ?? [];
ok(
	'with no From header the message from is used',
	($payload['from'] ?? '') === 'fallback@example.com',
);
ok('Reply-To with the capital T is read too', ($payload['replyTo'] ?? '') === '');
ok(
	'a message with no html part crosses null rather than an empty string',
	$payload['html'] === null,
);

install_host(['cfwMail' => new HostSpy(['ok' => false, 'error' => 'binding rejected the domain'])]);
$channel = install_logger();
ok('a refused send reports false', $mail->mail(['to' => 'b@example.com']) === false);
ok(
	'and the host error is logged rather than swallowed',
	($channel->errors[0][1]['@error'] ?? '') === 'binding rejected the domain',
);

Drupal::unsetContainer();
install_host([]);
// #endregion
// #region the cache backend factory
echo "\n# the cache factory, which is the seam core's own factory does not offer\n";

/**
 * A database connection that connects to nothing.
 *
 * `Connection` is abstract and every concrete driver lives in a MODULE namespace that composer's
 * autoloader does not map, so a stub is the only way to construct a cache backend here. The
 * constructor is overridden away because the parent's takes a live PDO handle, and nothing under
 * test issues a query -- the factory only passes this through.
 */
class StubConnection extends Connection
{
	/**
	 * Skips the parent constructor, which wants a PDO handle.
	 */
	public function __construct() {}

	/**
	 * {@inheritdoc}
	 */
	public static function open(array &$connection_options = [])
	{
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function upsert($table, array $options = [])
	{
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function schema()
	{
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function queryRange($query, $from, $count, array $args = [], array $options = [])
	{
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function driver()
	{
		return 'cfw_do_sqlite';
	}

	/**
	 * {@inheritdoc}
	 */
	public function databaseType()
	{
		return 'sqlite';
	}

	/**
	 * {@inheritdoc}
	 */
	public function createDatabase($database) {}

	/**
	 * {@inheritdoc}
	 */
	public function mapConditionOperator($operator)
	{
		return null;
	}
}

/**
 * The tag checksum service, reduced to the interface the backend stores.
 */
class StubChecksum implements CacheTagsChecksumInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function getCurrentChecksum(array $tags)
	{
		return 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isValid($checksum, array $tags)
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function reset() {}

	/**
	 * {@inheritdoc}
	 */
	public function invalidateTags(array $tags) {}
}

/**
 * Builds the factory with its parent's five properties filled by reflection.
 *
 * Core's factory takes them through a constructor that also wants a live Settings object, and
 * the point here is the ONE method the subclass overrides, so the parent state is set directly.
 *
 * @param int|null $maxRows
 *   The per-bin row cap to report, or NULL for core's default.
 *
 * @return CfwCacheBackendFactory
 *   A factory ready to answer get().
 */
function cache_factory(?int $maxRows = null): CfwCacheBackendFactory
{
	$factory = (new ReflectionClass(
		CfwCacheBackendFactory::class,
	))->newInstanceWithoutConstructor();
	$parent = new ReflectionClass(DatabaseBackendFactory::class);
	$values = [
		'connection' => new StubConnection(),
		'checksumProvider' => new StubChecksum(),
		'settings' => new Settings(
			$maxRows === null ? [] : ['database_cache_max_rows' => ['default' => $maxRows]],
		),
		'serializer' => new PhpSerialize(),
		'time' => new Time(),
	];
	foreach ($values as $name => $value) {
		$parent->getProperty($name)->setValue($factory, $value);
	}
	return $factory;
}

$factory = cache_factory();
$backend = $factory->get('render');
// core's factory constructs DatabaseBackend directly rather than resolving a class name, so a
// subclass of the FACTORY is the only seam
ok('get() hands back the subclass, not core\'s backend', $backend instanceof CfwCacheBackend);
ok('which is still a core database backend', $backend instanceof DatabaseBackend);
// core prefixes every bin with `cache_` inside the constructor, so the factory is handed the
// bare name and the table name comes back out
ok(
	'the bin it was asked for is the bin it built',
	(new ReflectionProperty(DatabaseBackend::class, 'bin'))->getValue($backend) === 'cache_render',
);
ok('a second bin gets its own backend', $factory->get('data') !== $backend);
ok(
	'and that one carries its own bin',
	(new ReflectionProperty(DatabaseBackend::class, 'bin'))->getValue($factory->get('data')) ===
		'cache_data',
);
// everything else is read from the parent, so a site that configured database_cache_max_rows
// keeps its setting
ok(
	'the connection comes from the parent rather than being invented',
	(new ReflectionProperty(DatabaseBackend::class, 'connection'))->getValue($backend) instanceof
		StubConnection,
);
$capped = cache_factory(42)->get('cache_render');
ok(
	'and a configured max-rows setting survives the swap',
	(new ReflectionProperty(DatabaseBackend::class, 'maxRows'))->getValue($capped) === 42,
);
ok(
	'CONTROL: with nothing configured the cap is core\'s default',
	(new ReflectionProperty(DatabaseBackend::class, 'maxRows'))->getValue($backend) ===
		DatabaseBackend::DEFAULT_MAX_ROWS,
);
// #endregion
// #region the guzzle fetch handler
echo "\n# the fetch handler, and the suspension it cannot have on the shipping build\n";

/**
 * The JS host object the runtime surfaces, reduced to fetch().
 */
class FetchHostSpy
{
	/**
	 * Every (url, init) pair it was handed.
	 *
	 * @var array
	 */
	public $calls = [];

	/**
	 * Builds a host whose fetch() answers with a fixed value.
	 *
	 * @param mixed $result
	 *   What the awaited promise resolves to.
	 */
	public function __construct(private mixed $result = null) {}

	/**
	 * Records the request and answers.
	 *
	 * @param string $url
	 *   The absolute URI.
	 * @param array $init
	 *   The fetch init object.
	 *
	 * @return mixed
	 *   Whatever this spy was built with.
	 */
	public function fetch(string $url, array $init): mixed
	{
		$this->calls[] = [$url, $init];
		return $this->result;
	}
}

/**
 * A reply shaped like the plain object the host hands back.
 */
class FetchReply
{
	/**
	 * Builds one reply.
	 *
	 * @param int $status
	 *   The HTTP status.
	 * @param array $headers
	 *   Response headers.
	 * @param string $body
	 *   The body.
	 */
	public function __construct(
		public int $status = 200,
		public array $headers = [],
		public string $body = '',
	) {}
}

/**
 * The rejection reason a promise carries, or an empty string.
 *
 * @param PromiseInterface $promise
 *   The promise to inspect.
 *
 * @return string
 *   The message, or '' when it fulfilled.
 */
function rejection_of(PromiseInterface $promise): string
{
	try {
		$promise->wait(true);
		return '';
	} catch (Throwable $reason) {
		return $reason->getMessage();
	}
}

$request = new Request('POST', 'https://api.example.test/v1/thing', ['X-One' => 'a'], 'payload');

// the contract, asserted before the shim exists. The class docblock says the shipping binary
// sets ASYNCIFY=0 and that `vrzno_await()` is therefore absent. This is that claim, measured:
// with the symbol missing the handler cannot complete a single request, and it fails as a
// rejected promise rather than as a fatal, so Guzzle surfaces it to the caller
ok('CONTROL: vrzno_await does not exist in a plain PHP process', !function_exists('vrzno_await'));
$hostSpy = new FetchHostSpy(new FetchReply());
$reason = rejection_of((new FetchHandler($hostSpy))($request, []));
ok('without it every request rejects', $reason !== '');
ok('naming the missing symbol', str_contains($reason, 'vrzno_await'));
// and it fails BEFORE the subrequest: PHP raises on the undefined function at the call setup,
// ahead of evaluating its argument, so `$this->host->fetch()` never runs. Measured, not assumed
ok('spending no subrequest on the way', count($hostSpy->calls) === 0);

if (!function_exists('vrzno_await')) {
	/**
	 * Stands in for vrzno's Asyncify/JSPI suspension, which resolves a JS promise.
	 *
	 * @param mixed $promise
	 *   What the host returned.
	 *
	 * @return mixed
	 *   Its resolved value. The real one suspends the interpreter; this one cannot, which is
	 *   exactly why the shipping build is guarded away from this class.
	 */
	function vrzno_await(mixed $promise): mixed
	{
		return $promise;
	}
}

$hostSpy = new FetchHostSpy(
	new FetchReply(201, ['Content-Type' => 'application/json'], '{"ok":1}'),
);
$response = (new FetchHandler($hostSpy))($request, [])->wait();
[$url, $init] = $hostSpy->calls[0];
ok('the absolute uri crosses', $url === 'https://api.example.test/v1/thing');
ok('the method crosses', ($init['method'] ?? '') === 'POST');
ok('headers are flattened to one string per name', ($init['headers']['X-One'] ?? '') === 'a');
ok('the body crosses', ($init['body'] ?? '') === 'payload');
// Cloudflare follows redirects by default and Guzzle expects to control it
ok('redirects are manual unless Guzzle asked for them', ($init['redirect'] ?? '') === 'manual');
ok('the response status comes back', $response->getStatusCode() === 201);
ok('with its headers', $response->getHeaderLine('Content-Type') === 'application/json');
ok('and its body', (string) $response->getBody() === '{"ok":1}');

$hostSpy = new FetchHostSpy(new FetchReply());
(new FetchHandler($hostSpy))($request, ['allow_redirects' => true]);
ok('and follow when it did', ($hostSpy->calls[0][1]['redirect'] ?? '') === 'follow');

$hostSpy = new FetchHostSpy(new FetchReply());
(new FetchHandler($hostSpy))(new Request('GET', 'https://api.example.test/'), []);
// an empty body must cross as null, not as '': a zero-length body turns a GET into a POST on
// some runtimes
ok('an empty body crosses as null', $hostSpy->calls[0][1]['body'] === null);

$reason = rejection_of((new FetchHandler(new FetchHostSpy('not an object')))($request, []));
ok(
	'a bridge that answers with a scalar is rejected',
	str_contains($reason, 'fetch bridge returned'),
);
ok('naming the type it got', str_contains($reason, 'string'));

$throwing = new class {
	/**
	 * Always throws, standing in for a host whose fetch is unavailable.
	 *
	 * @param string $url
	 *   Ignored.
	 * @param array $init
	 *   Ignored.
	 *
	 * @return mixed
	 *   Never returns.
	 */
	public function fetch(string $url, array $init): mixed
	{
		throw new RuntimeException('the runtime refused the subrequest');
	}
};
$reason = rejection_of((new FetchHandler($throwing))($request, []));
ok(
	'a host that raises becomes a rejection, not a fatal',
	str_contains($reason, 'refused the subrequest'),
);

// the constructor's default path reads the host off the bridge, which is how the container
// builds it when no argument is given
install_host(['cfHost' => new FetchHostSpy(new FetchReply(204))]);
$response = (new FetchHandler())($request, [])->wait();
ok('with no argument it takes cfHost off the bridge', $response->getStatusCode() === 204);
install_host([]);
// #endregion
// #region the handler the SHIPPING build gets
echo "\n# CachedFetchHandler, which is what a non-suspending build routes httpClient() through\n";

// THE DEFECT THIS EXISTS FOR, restated as a control: core's StreamHandler reads
// $http_response_header after opening the URL, and no userland stream wrapper can populate it.
// PHP 8.4 replaced the magic local with a function, and that answers NULL for the same reason --
// so on 8.5 the read fails through the supported route too
ok('CONTROL: a userland wrapper cannot set $http_response_header', !isset($http_response_header));
if (function_exists('http_get_last_response_headers')) {
	ok(
		'CONTROL: nor does its 8.4 replacement answer for one',
		http_get_last_response_headers() === null,
	);
} else {
	ok('CONTROL: the 8.4 replacement is absent on this PHP', true);
}

$cachedSpy = new HostSpy([
	'ok' => true,
	'status' => 200,
	'headers' => ['content-type' => 'application/json'],
	'body' => '{"advisories":[]}',
]);
install_host(['cfwFetch' => $cachedSpy]);
$response = (new CachedFetchHandler())($request, [])->wait();
ok('a cached response comes back as a real PSR-7 response', $response->getStatusCode() === 200);
ok('with its headers', $response->getHeaderLine('content-type') === 'application/json');
ok('and its body', (string) $response->getBody() === '{"advisories":[]}');
ok('the absolute uri crosses', ($cachedSpy->calls[0]['url'] ?? '') === (string) $request->getUri());
ok('the method crosses', ($cachedSpy->calls[0]['method'] ?? '') === 'POST');
// the cache is keyed method + url + BODY, so two POSTs to one endpoint differing only in their
// payload must not read each other's answer
ok('and the body crosses', ($cachedSpy->calls[0]['body'] ?? '') === 'payload');

// a refusal is a REJECTION, not a 202. Guzzle's http_errors middleware does not raise on a 2xx,
// so a deferral dressed as success would be decoded as the payload by every caller
install_host([
	'cfwFetch' => new HostSpy(['ok' => false, 'error' => 'not in the fetch cache; queued']),
]);
$reason = rejection_of((new CachedFetchHandler())($request, []));
ok('an uncached request rejects rather than answering 202', $reason !== '');
ok('naming what the host said', str_contains($reason, 'queued'));

// no bridge at all is the same shape: Host::call refuses rather than raising
install_host([]);
$reason = rejection_of((new CachedFetchHandler())($request, []));
ok('a build with no fetch capability rejects too', str_contains($reason, 'cfwFetch'));

install_host([
	'cfwFetch' => static function (string $json): string {
		throw new RuntimeException('the runtime refused the subrequest');
	},
]);
$reason = rejection_of((new CachedFetchHandler())($request, []));
ok(
	'a host that raises becomes a rejection, not a fatal',
	str_contains($reason, 'refused the subrequest'),
);

// a reply that claims ok with nothing else must not become a 200 with an invented body
install_host(['cfwFetch' => new HostSpy(['ok' => true])]);
$response = (new CachedFetchHandler())($request, [])->wait();
ok('a bodiless ok reply is a 200 with an empty body', $response->getStatusCode() === 200);
ok('and no invented headers', $response->getHeaders() === []);

// header values arrive as one string per name and PSR-7 wants a list; anything structured is
// dropped rather than cast, because "Array" as a header value is worse than a missing header
install_host([
	'cfwFetch' => new HostSpy([
		'ok' => true,
		'status' => 302,
		'headers' => ['location' => '/next', 'x-bad' => ['a', 'b']],
		'body' => '',
	]),
]);
$response = (new CachedFetchHandler())($request, [])->wait();
ok('a status other than 200 survives', $response->getStatusCode() === 302);
ok('a scalar header becomes a single-element list', $response->getHeader('location') === ['/next']);
ok('and a structured one is dropped', !$response->hasHeader('x-bad'));
install_host([]);
// #endregion
// #region the image toolkit
echo "\n# the image toolkit that never processes an image\n";

/**
 * Builds a valid PNG of the given size, header-accurate, without gd.
 *
 * Getimagesize() reads only the header, so the pixel data just has to inflate; what the assertions
 * below need is a file whose width and height are distinguishable from each other.
 *
 * @param int $width
 *   Width in pixels.
 * @param int $height
 *   Height in pixels.
 *
 * @return string
 *   The PNG bytes.
 */
function png_bytes(int $width, int $height): string
{
	$chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) .
		$type .
		$data .
		pack('N', crc32($type . $data));
	// 8-bit greyscale, no interlace
	$ihdr = pack('NN', $width, $height) . chr(8) . chr(0) . chr(0) . chr(0) . chr(0);
	$raw = str_repeat(chr(0) . str_repeat(chr(0), $width), $height);
	return "\x89PNG\r\n\x1a\n" .
		$chunk('IHDR', $ihdr) .
		$chunk('IDAT', (string) gzcompress($raw)) .
		$chunk('IEND', '');
}

/**
 * A toolkit with no plugin machinery behind it.
 *
 * ImageToolkitBase wants an operation manager, a logger and a config factory; nothing reachable
 * from the methods under test reads any of them, and `source` is untyped so it starts NULL --
 * which is the unparsed state the first assertions are about.
 *
 * @return CfwImageToolkit
 *   A fresh toolkit.
 */
function toolkit(): CfwImageToolkit
{
	return (new ReflectionClass(CfwImageToolkit::class))->newInstanceWithoutConstructor();
}

$tmp = sys_get_temp_dir() . '/cfw-toolkit-' . getmypid();
@mkdir($tmp, 0777, true);
$png = $tmp . '/source.png';
file_put_contents($png, png_bytes(7, 3));

$image = toolkit();
ok('a toolkit with nothing parsed is not valid', $image->isValid() === false);
ok('and reports no dimensions yet', $image->getWidth() === null && $image->getHeight() === null);
ok('parseFile refuses when no source was set', $image->parseFile() === false);

$image->setSource($png);
ok('parseFile reads a real PNG header', $image->parseFile() === true);
// the whole job of this toolkit: Drupal's render pipeline emits width and height attributes and
// they have to be the source's, because nothing downstream will ever resize the file
ok('and records the width', $image->getWidth() === 7);
ok('and the height, which is not the width', $image->getHeight() === 3);
ok('and the mime type from the header', $image->getMimeType() === 'image/png');
ok('so the toolkit is now valid', $image->isValid());

$notAnImage = $tmp . '/notes.txt';
file_put_contents($notAnImage, 'this is not an image');
$broken = toolkit();
$broken->setSource($notAnImage);
ok('parseFile refuses a file that is not an image', $broken->parseFile() === false);
ok('and stays invalid rather than half-parsed', $broken->isValid() === false);
ok('with no mime type invented for it', $broken->getMimeType() === '');

$missing = toolkit();
$missing->setSource($tmp . '/nothing-here.png');
ok('parseFile refuses a path that does not exist', $missing->parseFile() === false);

// an image style plugin calls this through apply(); reporting the size the manipulation WOULD
// have produced is what keeps the rendered markup correct while no pixels move
$image->setDimensions(100, 50);
ok('setDimensions records what a manipulation would have produced', $image->getWidth() === 100);
ok('and the matching height', $image->getHeight() === 50);
ok(
	'a toolkit told nothing is invalid again',
	(static function (): bool {
		$t = toolkit();
		$t->setDimensions(null, null);
		return !$t->isValid();
	})(),
);

$before = (string) file_get_contents($png);
ok('saving over the source is a no-op that reports success', $image->save($png) === true);
ok('and does not rewrite the file', (string) file_get_contents($png) === $before);

$derivative = $tmp . '/styles/thumbnail.png';
@mkdir(dirname($derivative), 0777, true);
ok('saving elsewhere reports success', $image->save($derivative) === true);
// the documented consequence: a style-derived file on disk IS the original, which is correct for
// delivery through an image-resizing CDN and wrong for anything reading the derivative's pixels
ok(
	'and the derivative is byte-identical to the source, not a resize',
	(string) file_get_contents($derivative) === $before,
);
ok(
	'a destination that cannot be written reports failure',
	@$image->save($tmp . '/no/such/dir/x.png') === false,
);

// ImageToolkitBase implements PluginFormInterface but leaves these abstract, so a toolkit that
// omits them is not a loadable class at all
$form = ['existing' => ['#type' => 'markup']];
ok(
	'buildConfigurationForm hands the form back unchanged',
	$image->buildConfigurationForm($form, new FormState()) === $form,
);
ok(
	'submitConfigurationForm stores nothing and returns nothing',
	(static function () use ($image, $form): bool {
		$state = new FormState();
		return $image->submitConfigurationForm($form, $state) === null;
	})(),
);

install_host([]);
ok('the toolkit is unavailable without the delivery capability', !CfwImageToolkit::isAvailable());
ok(
	'and deliveryUrl refuses rather than inventing a URL',
	CfwImageToolkit::deliveryUrl('public://a.png', ['width' => 10]) === null,
);

$imageSpy = new HostSpy(['ok' => true, 'url' => 'https://cdn.test/cdn-cgi/image/width=320/a.png']);
install_host(['cfwImageUrl' => $imageSpy]);
ok('it becomes available once the host exposes delivery', CfwImageToolkit::isAvailable());
ok(
	'deliveryUrl returns the host URL',
	CfwImageToolkit::deliveryUrl('public://a.png', ['width' => 320, 'fit' => 'cover']) ===
		'https://cdn.test/cdn-cgi/image/width=320/a.png',
);
$sent = $imageSpy->calls[0] ?? [];
ok('the source uri crosses', ($sent['uri'] ?? '') === 'public://a.png');
ok(
	'and the whole transform with it',
	($sent['transform'] ?? []) === ['width' => 320, 'fit' => 'cover'],
);

install_host(['cfwImageUrl' => new HostSpy(['ok' => false, 'error' => 'no images binding'])]);
ok(
	'a refused delivery is NULL rather than an empty string',
	CfwImageToolkit::deliveryUrl('public://a.png', []) === null,
);

// gd is not compiled in, and these are the formats the delivery layer serves
$extensions = CfwImageToolkit::getSupportedExtensions();
ok('webp is offered', in_array('webp', $extensions, true));
ok('and avif, which gd would not have given us', in_array('avif', $extensions, true));
ok(
	'and the jpeg spellings core asks for',
	in_array('jpe', $extensions, true) &&
		in_array('jpeg', $extensions, true) &&
		in_array('jpg', $extensions, true),
);

@unlink($derivative);
@rmdir(dirname($derivative));
@unlink($png);
@unlink($notAnImage);
@rmdir($tmp);
install_host([]);
// #endregion
// #region crypto over a host bridge
echo "\n# crypto routed to the platform primitive rather than the in-wasm fallback\n";

// the assertions further up ran with no bridge and exercised the hash() fallback; these are the
// crypto.subtle half, which nothing had reached
$digestSpy = new HostSpy(['ok' => true, 'hex' => str_repeat('AB', 32)]);
install_host(['cfwDigest' => $digestSpy]);
ok(
	'digest() prefers the host and lower-cases what it returns',
	CryptoShim::digest('abc', 'sha256') === str_repeat('ab', 32),
);
ok(
	'the subtle spelling crosses, not PHP\'s',
	($digestSpy->calls[0]['algorithm'] ?? '') === 'SHA-256',
);
ok('and the data', ($digestSpy->calls[0]['data'] ?? '') === 'abc');
ok(
	'binary mode unhexes the host answer',
	CryptoShim::digest('abc', 'sha256', true) === hex2bin(str_repeat('ab', 32)),
);
// md5 is absent from crypto.subtle by specification, so the host is not asked for it at all
$digestSpy = new HostSpy(['ok' => true, 'hex' => 'deadbeef']);
install_host(['cfwDigest' => $digestSpy]);
ok(
	'an algorithm crypto.subtle lacks falls back to hash()',
	CryptoShim::digest('abc', 'md5') === md5('abc'),
);
ok('and the host was never asked', $digestSpy->calls === []);

// a wrong digest is accepted by every caller and detected by none, so a bad bridge must refuse
install_host(['cfwDigest' => new HostSpy(['ok' => false, 'error' => 'subtle unavailable'])]);
ok('a failed bridge refuses', throws(static fn() => CryptoShim::digest('abc', 'sha256')));
ok(
	'and names the reason the host gave',
	str_contains(
		refusal_message(static fn() => CryptoShim::digest('abc', 'sha256')),
		'subtle unavailable',
	),
);
install_host(['cfwDigest' => new HostSpy(['ok' => true, 'hex' => 'not hex at all'])]);
ok('a non-hex answer refuses', throws(static fn() => CryptoShim::digest('abc', 'sha256')));
ok(
	'and says it was malformed',
	str_contains(refusal_message(static fn() => CryptoShim::digest('abc', 'sha256')), 'malformed'),
);
install_host(['cfwDigest' => new HostSpy(['ok' => true, 'hex' => 'abc'])]);
ok('an odd-length hex string refuses', throws(static fn() => CryptoShim::digest('abc', 'sha256')));
install_host(['cfwDigest' => new HostSpy(['ok' => true])]);
ok('and a missing hex field refuses', throws(static fn() => CryptoShim::digest('abc', 'sha256')));
ok(
	'naming that it returned nothing',
	str_contains(refusal_message(static fn() => CryptoShim::digest('abc', 'sha256')), 'nothing'),
);

$hmacSpy = new HostSpy(['ok' => true, 'hex' => str_repeat('cd', 32)]);
install_host(['cfwHmac' => $hmacSpy]);
ok('hmac() prefers the host too', CryptoShim::hmac('sha256', 'abc', 'k') === str_repeat('cd', 32));
$sent = $hmacSpy->calls[0] ?? [];
ok('the key crosses', ($sent['key'] ?? '') === 'k');
ok('with the subtle algorithm name', ($sent['algorithm'] ?? '') === 'SHA-256');
ok(
	'binary mode unhexes it',
	CryptoShim::hmac('sha256', 'abc', 'k', true) === hex2bin(str_repeat('cd', 32)),
);
// the empty-key refusal comes BEFORE the bridge, so a host cannot be talked into a forgeable MAC
$hmacSpy = new HostSpy(['ok' => true, 'hex' => 'ab']);
install_host(['cfwHmac' => $hmacSpy]);
ok(
	'an empty key is still refused with a host present',
	throws(static fn() => CryptoShim::hmac('sha256', 'a', '')),
);
ok('and the host was not asked', $hmacSpy->calls === []);
install_host(['cfwHmac' => new HostSpy(['ok' => false, 'error' => 'key import failed'])]);
ok('a failed hmac bridge refuses', throws(static fn() => CryptoShim::hmac('sha256', 'abc', 'k')));

$randomSpy = new HostSpy(['ok' => true, 'hex' => str_repeat('7f', 16)]);
install_host(['cfwRandom' => $randomSpy]);
$bytes = CryptoShim::randomBytes(16);
ok('randomBytes() prefers crypto.getRandomValues', $bytes === hex2bin(str_repeat('7f', 16)));
ok('asking for exactly the length wanted', ($randomSpy->calls[0]['length'] ?? null) === 16);
// padding a short read would weaken a key silently
install_host(['cfwRandom' => new HostSpy(['ok' => true, 'hex' => str_repeat('7f', 8)])]);
ok(
	'a short read is refused rather than padded',
	throws(static fn() => CryptoShim::randomBytes(16)),
);
ok(
	'and both lengths are named',
	str_contains(
		refusal_message(static fn() => CryptoShim::randomBytes(16)),
		'8 bytes for a 16-byte',
	),
);
install_host(['cfwRandom' => new HostSpy(['ok' => false, 'error' => 'no entropy source'])]);
ok('a failed random bridge refuses', throws(static fn() => CryptoShim::randomBytes(8)));
install_host([]);
ok('and with no bridge the in-wasm CSPRNG still answers', strlen(CryptoShim::randomBytes(8)) === 8);
// #endregion
// #region the deferred HTTP handler
echo "\n# the deferred HTTP handler, which answers 202 instead of suspending\n";

$deferred = new CfwDeferredHttp();
$get = new Request('GET', 'https://example.test/feed', ['Accept' => ['a/b', 'c/d']]);

install_host([
	'cfwHttpCacheGet' => new HostSpy([
		'ok' => true,
		'status' => 200,
		'headers' => ['content-type' => 'application/json'],
		'body' => '{"cached":1}',
	]),
]);
$response = $deferred($get, [])->wait();
ok('a cached body is served without queueing', $response->getStatusCode() === 200);
ok('with its body', (string) $response->getBody() === '{"cached":1}');
ok(
	'and its headers back as PSR-7 lists',
	$response->getHeaderLine('content-type') === 'application/json',
);

// the cache is only consulted for GET/HEAD, or when the queue capability exists at all
$cacheSpy = new HostSpy(['ok' => false]);
install_host(['cfwHttpCacheGet' => $cacheSpy]);
$deferred(new Request('POST', 'https://example.test/p', [], 'body'), []);
ok('a POST with no queue capability skips the cache entirely', $cacheSpy->calls === []);

$cacheSpy = new HostSpy(['ok' => false]);
$queueSpy = new HostSpy(['ok' => true]);
install_host(['cfwHttpCacheGet' => $cacheSpy, 'cfwQueueFetch' => $queueSpy]);
$response = $deferred(new Request('POST', 'https://example.test/p', [], 'body'), [])->wait();
ok('a POST WITH one does consult it, which is the control', count($cacheSpy->calls) === 1);
ok('a queued request answers 202', $response->getStatusCode() === 202);
ok(
	'and says so in a header a caller can branch on',
	$response->getHeaderLine('x-cfw-deferred') === 'queued',
);
$body = json_decode((string) $response->getBody(), true);
ok('the body reports the deferral', ($body['deferred'] ?? null) === true);
ok('and echoes the url', ($body['url'] ?? '') === 'https://example.test/p');
// present and NULL rather than absent: a caller reading the field must see "no error" and not
// have to tell an absent key from a null one
ok(
	'with the error field present and null',
	array_key_exists('error', $body) && $body['error'] === null,
);
ok(
	'and the request reached the queue',
	($queueSpy->calls[0]['url'] ?? '') === 'https://example.test/p',
);
ok('with its method', ($queueSpy->calls[0]['method'] ?? '') === 'POST');
ok('and its body', ($queueSpy->calls[0]['body'] ?? '') === 'body');

// Guzzle gives header values as lists and the host takes one string per name
install_host([
	'cfwHttpCacheGet' => new HostSpy(['ok' => false]),
	'cfwQueueFetch' => ($queueSpy = new HostSpy(['ok' => true])),
]);
$deferred($get, []);
ok(
	'a multi-value header is flattened to one comma-joined string',
	($queueSpy->calls[0]['headers']['Accept'] ?? '') === 'a/b, c/d',
);

install_host(['cfwQueueFetch' => new HostSpy(['ok' => false, 'error' => 'queue is full'])]);
$response = $deferred($get, [])->wait();
ok('a queue that refuses answers 503, not 202', $response->getStatusCode() === 503);
ok('and marks the deferral failed', $response->getHeaderLine('x-cfw-deferred') === 'failed');
ok(
	'quoting the reason, so a caller can tell',
	(json_decode((string) $response->getBody(), true)['error'] ?? '') === 'queue is full',
);

// the synchronous lane, which only exists on a build that can suspend
$syncSpy = new HostSpy([
	'ok' => true,
	'status' => 201,
	'headers' => ['x-a' => 'b'],
	'body' => 'live',
]);
install_host([
	'cfwFetchSync' => $syncSpy,
	'cfwQueueFetch' => ($queueSpy = new HostSpy(['ok' => true])),
]);
$response = $deferred($get, ['cfw_sync' => true])->wait();
ok(
	'a caller that asked for sync, on a host that has it, gets the live answer',
	$response->getStatusCode() === 201,
);
ok('with the live body', (string) $response->getBody() === 'live');
ok('and nothing was queued', $queueSpy->calls === []);
ok(
	'a caller that did NOT ask still gets 202',
	$deferred($get, [])->wait()->getStatusCode() === 202,
);

install_host([
	'cfwFetchSync' => new HostSpy(['ok' => false, 'error' => 'upstream refused']),
	'cfwQueueFetch' => new HostSpy(['ok' => true]),
]);
ok(
	'a failed sync attempt falls through to the queue rather than erroring',
	$deferred($get, ['cfw_sync' => true])
		->wait()
		->getStatusCode() === 202,
);

install_host([
	'cfwHttpCacheGet' => new HostSpy([
		'ok' => true,
		'status' => 200,
		'headers' => 'not an array',
		'body' => 'x',
	]),
	'cfwQueueFetch' => new HostSpy(['ok' => true]),
]);
ok(
	'a malformed headers field yields no headers rather than a fatal',
	$deferred($get, [])->wait()->getHeaders() === [],
);
install_host([]);
// #endregion
// #region the status report
echo "\n# the status report, which is how a site learns the wrapper did not take\n";

$requirements = new Requirements();

install_host(['cfwMail' => new HostSpy(), 'cfwLog' => new HostSpy()]);
$report = $requirements->runtimeRequirements();
ok(
	'a partial capability set is OK rather than a fault',
	$report['drupflare_capabilities']['severity'] === RequirementSeverity::OK,
);
ok(
	'and the count is reported',
	$report['drupflare_capabilities']['value']->getArguments()['@count'] === 2,
);
ok(
	'against the total this module knows about',
	$report['drupflare_capabilities']['value']->getArguments()['@total'] ===
		count(Requirements::CAPABILITIES),
);
ok(
	'with the absent ones named so a deployment gap is diagnosable',
	str_contains(
		(string) ($report['drupflare_capabilities']['description']->getArguments()['@absent'] ??
			''),
		'cfwImageUrl',
	),
);
ok(
	'and the installed ones NOT named as absent',
	!str_contains(
		(string) ($report['drupflare_capabilities']['description']->getArguments()['@absent'] ??
			''),
		'cfwMail',
	),
);

$all = [];
foreach (Requirements::CAPABILITIES as $capability) {
	$all[$capability] = new HostSpy();
}
$all['cfwFileRead'] = new HostSpy();
install_host($all);
$report = $requirements->runtimeRequirements();
ok(
	'a complete set carries no description at all',
	$report['drupflare_capabilities']['description'] === null,
);

// the durable-file entry appears only when the host installed the capability, and reports what is
// IN FORCE rather than what this module would prefer -- core re-registers its wrappers after
// modules load
ok('the durable-file entry appears with the capability', isset($report['drupflare_file_wrapper']));
ok(
	'and reports no scheme while core still owns them',
	(string) ($report['drupflare_file_wrapper']['value']->getArguments()['@schemes'] ?? '') === '',
);
CfwFileStreamWrapper::register();
$report = $requirements->runtimeRequirements();
ok(
	'and names both once this module holds them',
	($report['drupflare_file_wrapper']['value']->getArguments()['@schemes'] ?? '') ===
		'public, private',
);
ok(
	'at OK severity, because either owner is a valid configuration',
	$report['drupflare_file_wrapper']['severity'] === RequirementSeverity::OK,
);
stream_wrapper_unregister('public');
stream_wrapper_unregister('private');

// one file_get_contents('https://...') with the native wrapper in place kills the invocation
// uncatchably, so an unregistered scheme is an Error rather than a warning
stream_wrapper_unregister('https');
$report = $requirements->runtimeRequirements();
ok(
	'a missing https wrapper is an ERROR on the status report',
	$report['drupflare_stream_wrapper']['severity'] === RequirementSeverity::Error,
);
ok(
	'naming the scheme that is missing',
	($report['drupflare_stream_wrapper']['value']->getArguments()['@schemes'] ?? '') === 'https',
);
ok(
	'and saying the module file did not load',
	str_contains(
		(string) ($report['drupflare_stream_wrapper']['description']->getArguments()['@schemes'] ??
			''),
		'https',
	),
);
stream_wrapper_restore('https');
$report = $requirements->runtimeRequirements();
ok(
	'CONTROL: with both registered the same entry is OK',
	$report['drupflare_stream_wrapper']['severity'] === RequirementSeverity::OK,
);
ok('and carries no description', $report['drupflare_stream_wrapper']['description'] === null);
install_host([]);
// A ROW EITHER WAY, and this used to assert the opposite. With the entry emitted only INSIDE
// `available()`, a deployment with no file capability got no row at all -- uploads land in MEMFS
// and vanish on eviction, reported nowhere. The absent case is the one that needs saying.
$absentReport = $requirements->runtimeRequirements();
ok(
	'the durable-file entry is present when the capability is NOT',
	isset($absentReport['drupflare_file_wrapper']),
);
ok(
	'and it is an error rather than a silent omission',
	($absentReport['drupflare_file_wrapper']['severity'] ?? null) === RequirementSeverity::Error,
);
// `getUntranslatedString()` rather than a string cast: `__toString()` reaches for the container's
// translation service, which this harness has no container for
ok(
	'and it says where the uploads go',
	str_contains(
		$absentReport['drupflare_file_wrapper']['description']->getUntranslatedString(),
		'in-memory filesystem',
	),
);
// #endregion
// #region the router dumper's skip
echo "\n# the router dump that does not rewrite the rows already in the table\n";

/**
 * The state backend the dumper records its fingerprint in.
 */
class StateSpy implements StateInterface
{
	/**
	 * Everything set so far, key to value.
	 *
	 * @var array
	 */
	private $values = [];

	/**
	 * {@inheritdoc}
	 */
	public function get($key, $default = null)
	{
		return $this->values[$key] ?? $default;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMultiple(array $keys)
	{
		$out = [];
		foreach ($keys as $key) {
			$out[$key] = $this->values[$key] ?? null;
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function set($key, $value)
	{
		$this->values[$key] = $value;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setMultiple(array $data)
	{
		foreach ($data as $key => $value) {
			$this->values[$key] = $value;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($key)
	{
		unset($this->values[$key]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteMultiple(array $keys)
	{
		foreach ($keys as $key) {
			unset($this->values[$key]);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function resetCache() {}

	/**
	 * {@inheritdoc}
	 */
	public function getValuesSetDuringRequest(string $key): ?array
	{
		return null;
	}
}

/**
 * A connection whose row count is scripted, so the table check can be driven both ways.
 */
class CountingConnection extends StubConnection
{
	/**
	 * Rows the router table reports, or NULL to raise as an unreadable table does.
	 *
	 * @var int|null
	 */
	public $rows = 0;

	/**
	 * {@inheritdoc}
	 */
	public function select($table, $alias = null, array $options = [])
	{
		if ($this->rows === null) {
			throw new RuntimeException('no such table: router');
		}
		return new CountingQuery($this->rows);
	}
}

/**
 * The three-call chain countRows() walks.
 */
class CountingQuery
{
	/**
	 * Builds a chain that answers one row count.
	 *
	 * @param int $rows
	 *   What fetchField() answers.
	 */
	public function __construct(private int $rows) {}

	/**
	 * {@inheritdoc}
	 */
	public function countQuery(): self
	{
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute(): self
	{
		return $this;
	}

	/**
	 * The row count.
	 *
	 * @return int
	 *   Rows in the table.
	 */
	public function fetchField(): int
	{
		return $this->rows;
	}
}

/**
 * A connection that scripts what `sqlite_master` holds and records the DDL aimed at it.
 */
class IndexSwapConnection extends StubConnection
{
	/**
	 * The recorded `CREATE INDEX` for `router_alias`, or FALSE when there is no such index.
	 *
	 * @var string|false
	 */
	public $existing = false;

	/**
	 * Whether to refuse every statement, standing in for a driver that will not run DDL.
	 *
	 * @var bool
	 */
	public $throw = false;

	/**
	 * Statements this connection was asked to run, minus the `sqlite_master` read.
	 *
	 * @var string[]
	 */
	public $ran = [];

	/**
	 * {@inheritdoc}
	 */
	public function query($query, array $args = [], $options = [])
	{
		if ($this->throw) {
			throw new RuntimeException('DDL refused');
		}
		if (str_contains((string) $query, 'sqlite_master')) {
			return new IndexSwapResult($this->existing);
		}
		$this->ran[] = (string) $query;
		return new IndexSwapResult(false);
	}
}

/**
 * The one-call chain the index swap walks.
 */
class IndexSwapResult
{
	/**
	 * Holds what fetchField() answers.
	 *
	 * @param string|false $field
	 *   The scripted field value.
	 */
	public function __construct(private $field) {}

	/**
	 * {@inheritdoc}
	 */
	public function fetchField()
	{
		return $this->field;
	}
}

/**
 * A dumper wired to a scripted state and connection.
 *
 * @param StateSpy $state
 *   The state backend.
 * @param StubConnection $connection
 *   The connection.
 * @param RouteCollection|null $routes
 *   The collection to dump.
 *
 * @return CfwMatcherDumper
 *   A dumper ready to answer dump().
 */
function dumper(
	StateSpy $state,
	StubConnection $connection,
	?RouteCollection $routes,
): CfwMatcherDumper {
	$dumper = (new ReflectionClass(CfwMatcherDumper::class))->newInstanceWithoutConstructor();
	$base = new ReflectionClass(MatcherDumper::class);
	$base->getProperty('state')->setValue($dumper, $state);
	$base->getProperty('connection')->setValue($dumper, $connection);
	$base->getProperty('tableName')->setValue($dumper, 'router');
	$base->getProperty('routes')->setValue($dumper, $routes);
	return $dumper;
}

$collection = new RouteCollection();
$collection->add('a', new Route('/a'));
$collection->add('b', new Route('/b'));

$fingerprint = (new ReflectionMethod(CfwMatcherDumper::class, 'fingerprint'))->invoke(
	null,
	$collection,
);
$state = new StateSpy();
$state->set(CfwMatcherDumper::FINGERPRINT_KEY, ['hash' => $fingerprint, 'rows' => 2095]);
$connection = new CountingConnection();
$connection->rows = 2095;

$dumps = CfwMatcherDumper::$dumps;
$skips = CfwMatcherDumper::$skips;
$dumper = dumper($state, $connection, $collection);
ok('an unchanged collection over an intact table writes nothing', $dumper->dump() === '');
ok('and is counted as a skip', CfwMatcherDumper::$skips === $skips + 1);
ok('while still counting as an attempted dump', CfwMatcherDumper::$dumps === $dumps + 1);
// the collection is released, or the next dump would see it again and skip a real change
ok(
	'and the collection is released',
	(new ReflectionProperty(MatcherDumper::class, 'routes'))->getValue($dumper) === null,
);

// CONTROL: the same table with a different row count is not intact, so the skip must not fire.
// A skip here would leave the router half-written and the site unroutable
$connection->rows = 12;
$skips = CfwMatcherDumper::$skips;
$tableMatches = new ReflectionMethod(CfwMatcherDumper::class, 'tableMatches');
ok(
	'CONTROL: a row count that disagrees is not a match',
	$tableMatches->invoke(dumper($state, $connection, $collection), 2095) === false,
);
$connection->rows = 2095;
ok(
	'and the matching count is, which is what makes the control mean something',
	$tableMatches->invoke(dumper($state, $connection, $collection), 2095) === true,
);
ok(
	'a stored count of zero never matches, so an empty table always rebuilds',
	$tableMatches->invoke(dumper($state, $connection, $collection), 0) === false,
);
ok(
	'and neither does a negative one',
	$tableMatches->invoke(dumper($state, $connection, $collection), -1) === false,
);
ok(
	'or a non-integer left by an older format',
	$tableMatches->invoke(dumper($state, $connection, $collection), 'lots') === false,
);

$countRows = new ReflectionMethod(CfwMatcherDumper::class, 'countRows');
$connection->rows = null;
ok(
	'an unreadable table counts -1 rather than raising into the dump',
	$countRows->invoke(dumper($state, $connection, $collection)) === -1,
);
$connection->rows = 419;
ok(
	'and a readable one reports its rows',
	$countRows->invoke(dumper($state, $connection, $collection)) === 419,
);

// a collection carrying something that is not a Route still fingerprints, by name
$odd = new RouteCollection();
$odd->add('a', new Route('/a'));
ok(
	'the fingerprint is stable across two identical collections',
	(new ReflectionMethod(CfwMatcherDumper::class, 'fingerprint'))->invoke(null, $odd) ===
		(new ReflectionMethod(CfwMatcherDumper::class, 'fingerprint'))->invoke(null, clone $odd),
);
// #endregion
// #region what the shutdown handler would ship
echo "\n# the fatal classifier, which is the half of the shutdown handler that can be wrong\n";

// the shutdown closure itself only runs at process teardown, after the coverage driver has
// stopped, so the decision it makes is split out where it can be driven
ok(
	'a plain warning is not shipped as a fatal',
	CfwLogger::fatalPayload([
		'type' => E_WARNING,
		'message' => 'undefined array key',
		'file' => '/x.php',
		'line' => 1,
	]) === null,
);
ok('and a notice is not either', CfwLogger::fatalPayload(['type' => E_NOTICE]) === null);
ok('a shutdown with no error at all ships nothing', CfwLogger::fatalPayload(null) === null);
ok('and neither does a malformed error array', CfwLogger::fatalPayload([]) === null);

foreach (
	[
		'E_ERROR' => E_ERROR,
		'E_PARSE' => E_PARSE,
		'E_CORE_ERROR' => E_CORE_ERROR,
		'E_COMPILE_ERROR' => E_COMPILE_ERROR,
	]
	as $label => $type
) {
	$payload = CfwLogger::fatalPayload([
		'type' => $type,
		'message' => 'boom',
		'file' => '/index.php',
		'line' => 42,
	]);
	ok("$label is shipped", $payload !== null);
	ok('and on the php-fatal channel', ($payload['channel'] ?? '') === 'php-fatal');
}
$payload = CfwLogger::fatalPayload([
	'type' => E_ERROR,
	'message' => 'Allowed memory size exhausted',
	'file' => '/index.php',
	'line' => 42,
]);
ok('at error level', ($payload['level'] ?? '') === 'error');
ok('carrying the message', ($payload['message'] ?? '') === 'Allowed memory size exhausted');
ok('the file', ($payload['file'] ?? '') === '/index.php');
ok('and the line, or the report names no location', ($payload['line'] ?? null) === 42);

// a log line carrying invalid UTF-8 must not take the request down with it
$logSpy = new HostSpy();
install_host(['cfwLog' => $logSpy]);
$threw = false;
try {
	(new CfwLogger(new ParserSpy()))->log(3, "bad bytes \xB1\x31 here", []);
} catch (Throwable $e) {
	$threw = true;
}
ok('a message that cannot be JSON-encoded is dropped, not thrown', !$threw);
ok('and nothing malformed reaches the host', $logSpy->calls === []);
install_host([]);
// #endregion
// #region the crypto refusal nothing had reached
$noEngine = 'sha3000';
ok(
	'hmac() refuses an algorithm neither the host nor hash() has',
	throws(static fn() => CryptoShim::hmac($noEngine, 'abc', 'k')),
);
ok(
	'and names both engines it tried',
	str_contains(
		refusal_message(static fn() => CryptoShim::hmac($noEngine, 'abc', 'k')),
		'cfwHmac',
	),
);
ok(
	'quoting the algorithm it could not compute',
	str_contains(
		refusal_message(static fn() => CryptoShim::hmac($noEngine, 'abc', 'k')),
		$noEngine,
	),
);
// #endregion
// #region the router dump that does write
echo "\n# and the dump that does write, so the fingerprint it records is the one a skip reads\n";

/**
 * The transaction handle core's dump opens and commits.
 */
class FakeTransaction
{
	/**
	 * Whether the dump committed rather than rolled back.
	 *
	 * @var bool
	 */
	public $committed = false;

	/**
	 * {@inheritdoc}
	 */
	public function commitOrRelease(): void
	{
		$this->committed = true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function rollBack(): void {}
}

/**
 * A no-op statement, for the calls core's dump chains onto.
 */
class FakeStatement
{
	/**
	 * Rows handed to values(), so an insert can be counted.
	 *
	 * @var array
	 */
	public array $rows = [];

	/**
	 * Builds a statement writing into one table.
	 *
	 * @param FakeRouterTable $table
	 *   Where the rows land.
	 */
	public function __construct(private FakeRouterTable $table) {}

	/**
	 * {@inheritdoc}
	 */
	public function fields(array $fields): self
	{
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function values(array $values): self
	{
		$this->rows[] = $values;
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute(): int
	{
		$this->table->rows += count($this->rows);
		return count($this->rows);
	}
}

/**
 * The router table's row count, shared between the fake connection's calls.
 */
class FakeRouterTable
{
	/**
	 * How many rows the table holds.
	 *
	 * @var int
	 */
	public $rows = 0;
}

/**
 * A connection that records a dump instead of running one.
 *
 * Enough of core's `MatcherDumper::dump()` to let it complete: a transaction, a delete and the
 * chunked inserts. It exists so the drupflare half -- recording the fingerprint and the row count
 * that the next dump compares against -- can be driven end to end.
 */
class DumpingConnection extends StubConnection
{
	/**
	 * The table the dump writes into.
	 *
	 * @var FakeRouterTable
	 */
	public $table;

	/**
	 * The transaction the last dump opened.
	 *
	 * @var FakeTransaction|null
	 */
	public $transaction = null;

	/**
	 * Builds a connection over an empty router table.
	 */
	public function __construct()
	{
		$this->table = new FakeRouterTable();
	}

	/**
	 * {@inheritdoc}
	 */
	public function startTransaction($name = '')
	{
		$this->transaction = new FakeTransaction();
		return $this->transaction;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($table, array $options = [])
	{
		$this->table->rows = 0;
		return new FakeStatement($this->table);
	}

	/**
	 * {@inheritdoc}
	 */
	public function insert($table, array $options = [])
	{
		return new FakeStatement($this->table);
	}

	/**
	 * {@inheritdoc}
	 */
	public function select($table, $alias = null, array $options = [])
	{
		return new CountingQuery($this->table->rows);
	}
}

$writing = new DumpingConnection();
$state = new StateSpy();
$dumper = dumper($state, $writing, $collection);
$dumps = CfwMatcherDumper::$dumps;
$skips = CfwMatcherDumper::$skips;

$dumper->dump();
ok('a first dump with no stored fingerprint writes the rows', $writing->table->rows > 0);
ok('inside a committed transaction', $writing->transaction->committed === true);
ok('and is not counted as a skip', CfwMatcherDumper::$skips === $skips);
ok('but is counted as a dump', CfwMatcherDumper::$dumps === $dumps + 1);

$stored = $state->get(CfwMatcherDumper::FINGERPRINT_KEY);
ok('the fingerprint is recorded', is_array($stored) && ($stored['hash'] ?? '') !== '');
ok('alongside the row count the table now holds', ($stored['rows'] ?? 0) === $writing->table->rows);
ok(
	'and it is the fingerprint of the collection that was dumped',
	($stored['hash'] ?? '') === $fingerprint,
);

// core's dump calls setOption('compiler_class') on every route it writes, and the fingerprint
// reads getOptions() -- so the fingerprint is taken BEFORE the dump, and a rebuild always brings
// a fresh collection. Moving that computation after parent::dump() would stop every skip firing
$fp = static fn(RouteCollection $c): string => (new ReflectionMethod(
	CfwMatcherDumper::class,
	'fingerprint',
))->invoke(null, $c);
ok(
	'a dumped collection is mutated by core and no longer fingerprints the same',
	$fp($collection) !== $fingerprint,
);

$rebuilt = new RouteCollection();
$rebuilt->add('a', new Route('/a'));
$rebuilt->add('b', new Route('/b'));
ok(
	'while a fresh identical one still does, which is what a rebuild supplies',
	$fp($rebuilt) === $fingerprint,
);

// THE ROUND TRIP, which is the whole feature: what the write recorded is what the next dump reads
$rowsAfterFirst = $writing->table->rows;
$second = dumper($state, $writing, $rebuilt);
$skips = CfwMatcherDumper::$skips;
ok('an identical second dump writes nothing', $second->dump() === '');
ok('and is counted as a skip', CfwMatcherDumper::$skips === $skips + 1);
ok(
	'leaving the table exactly as the first dump left it',
	$writing->table->rows === $rowsAfterFirst,
);

// CONTROL: a changed collection must NOT skip, or a real route change never reaches the table
$changed = new RouteCollection();
$changed->add('a', new Route('/a'));
$changed->add('b', new Route('/moved'));
$skips = CfwMatcherDumper::$skips;
$third = dumper($state, $writing, $changed);
$third->dump();
ok(
	'CONTROL: a changed collection is dumped rather than skipped',
	CfwMatcherDumper::$skips === $skips,
);
ok(
	'and the recorded fingerprint moves with it',
	($state->get(CfwMatcherDumper::FINGERPRINT_KEY)['hash'] ?? '') !== $fingerprint,
);
// #endregion
// #region the partial alias index
$swap = new IndexSwapConnection();
$swap->existing = 'CREATE INDEX "router_alias" ON "router" ("alias")';
$dumper = dumper(new StateSpy(), $swap, null);
(new ReflectionMethod(CfwMatcherDumper::class, 'ensurePartialAliasIndex'))->invoke($dumper);

ok('a full alias index is dropped', in_array('DROP INDEX router_alias', $swap->ran, true));
ok(
	'and replaced with one that stores only the rows carrying an alias',
	(bool) preg_grep('/CREATE INDEX .*WHERE alias IS NOT NULL/', $swap->ran),
);

// IDEMPOTENT: the pack already ships the partial form, so the common case must touch nothing.
// A swap that ran on every dump would drop and rebuild 17 index rows for no reason
$swap = new IndexSwapConnection();
$swap->existing = 'CREATE INDEX "router_alias" ON "router" ("alias") WHERE "alias" IS NOT NULL';
$dumper = dumper(new StateSpy(), $swap, null);
(new ReflectionMethod(CfwMatcherDumper::class, 'ensurePartialAliasIndex'))->invoke($dumper);
ok('an index that is already partial is left alone', $swap->ran === []);

// a table with no alias index at all gets the partial one without a DROP first
$swap = new IndexSwapConnection();
$swap->existing = false;
$dumper = dumper(new StateSpy(), $swap, null);
(new ReflectionMethod(CfwMatcherDumper::class, 'ensurePartialAliasIndex'))->invoke($dumper);
ok('a missing index is created partial', count($swap->ran) === 1);
ok('and nothing is dropped', !in_array('DROP INDEX router_alias', $swap->ran, true));

// NEVER FATAL: the full index costs rows, it does not break the site, so a connection that
// refuses DDL must leave the dump working rather than taking the request down
$swap = new IndexSwapConnection();
$swap->throw = true;
$dumper = dumper(new StateSpy(), $swap, null);
$survived = true;
try {
	(new ReflectionMethod(CfwMatcherDumper::class, 'ensurePartialAliasIndex'))->invoke($dumper);
} catch (Throwable) {
	$survived = false;
}
ok('a connection that refuses the swap does not take the dump down', $survived);

// core's own index entry is gone from the schema, or createTable() would build the full one first
$schema = (new ReflectionMethod(CfwMatcherDumper::class, 'schemaDefinition'))->invoke(
	dumper(new StateSpy(), new IndexSwapConnection(), null),
);
ok('the schema no longer declares core full alias index', !isset($schema['indexes']['alias']));
ok(
	'while the pattern_outline index it shares the table with survives',
	isset($schema['indexes']['pattern_outline_parts']),
);
// #endregion
// #region P45 -- a capability is SHIMMED, ACCOMMODATED or DECLARED, never silently absent
echo "\n# Degradation -- the declared-degradation contract\n";

Degradation::reset();
ok('nothing is declared on a fresh boot', Degradation::all() === []);
ok('and a capability nobody declared reads as not declared', !Degradation::isDeclared('nope'));

Degradation::record('sodium_crypto_generichash', 'no sodium and no blake2b in ext-hash');
ok('recording a degradation registers it', Degradation::isDeclared('sodium_crypto_generichash'));
$entry = Degradation::all()['sodium_crypto_generichash'];
ok('it carries the reason an operator has to act on', str_contains($entry['reason'], 'ext-hash'));
ok('it names the caller, so the gap is traceable to code', $entry['caller'] !== 'unknown');
// blocked is the default because an unknown state must never read as a weaker claim than it is
ok('and defaults to blocked rather than to something softer', $entry['state'] === 'blocked');

// ONCE PER BOOT is the whole point: a degraded function inside a render loop would otherwise
// write thousands of identical watchdog rows and spend the meter that binds regeneration
Degradation::record('sodium_crypto_generichash', 'a different reason entirely');
ok('a second record for the same capability does not add a row', count(Degradation::all()) === 1);
ok(
	'and does not overwrite the first reason',
	Degradation::all()['sodium_crypto_generichash']['reason'] ===
		'no sodium and no blake2b in ext-hash',
);

Degradation::record('cfw_probe_untested', 'nothing has proven this either way', 'untested');
ok('a second capability IS a second row', count(Degradation::all()) === 2);
ok(
	'and keeps the state it was given',
	Degradation::all()['cfw_probe_untested']['state'] === 'untested',
);

// the module table's vocabulary, deliberately, and `supported` is absent from both for the same
// reason: it meant "measured WITHOUT the thing that needs it" and read as a promise
Degradation::record('cfw_probe_bogus', 'an unknown state must not soften the claim', 'supported');
ok(
	'an unrecognised state is recorded as blocked rather than trusted',
	Degradation::all()['cfw_probe_bogus']['state'] === 'blocked',
);
ok(
	'and `supported` is not in the vocabulary at all',
	!in_array('supported', Degradation::STATES, true),
);

$rows = Degradation::requirements();
ok('every declaration surfaces a status-report row', count($rows) === 3);
$keys = array_keys($rows);
ok(
	'keyed safely for a requirements array',
	preg_match('/^drupflare_degraded_[a-z0-9_]+$/i', $keys[0]) === 1,
);
$blocked = $rows['drupflare_degraded_sodium_crypto_generichash'];
ok('a blocked capability reports as an Error', $blocked['severity'] === RequirementSeverity::Error);
ok(
	'an untested one reports as a Warning, which is a weaker claim and a different colour',
	$rows['drupflare_degraded_cfw_probe_untested']['severity'] === RequirementSeverity::Warning,
);

// (a) of the contract: recording CANNOT fatal. There is no host bridge in this suite, so
// Host::call throws -- and a declaration that died would be worse than the gap it describes
$survived = true;
try {
	Degradation::record('cfw_probe_nohost', 'the logger is unreachable from here');
} catch (Throwable) {
	$survived = false;
}
ok('a declaration survives having no host bridge to log through', $survived);
ok(
	'and is still recorded when the log could not be written',
	Degradation::isDeclared('cfw_probe_nohost'),
);

Degradation::reset();
ok('reset clears the registry for the next boot', Degradation::all() === []);

// A CALL SITE THAT TRANSPOSES ITS ARGUMENTS IS INVISIBLE, and two shipped ones did. `record()` takes
// (capability, reason, state), so passing a state second destroys the reason while the unrecognised
// third argument falls back to `blocked` and the row still looks right. Checked over the SOURCE
// because each site needs a live Drupal to reach.
$callSites = 0;
$transposed = [];
$srcRoot = dirname(__DIR__) . '/src';
$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));
foreach ($phpFiles as $file) {
	if (!$file->isFile() || $file->getExtension() !== 'php') {
		continue;
	}
	$body = (string) file_get_contents($file->getPathname());
	if (!preg_match_all('/Degradation::record\(\s*(.+?)\);/s', $body, $matches)) {
		continue;
	}
	foreach ($matches[1] as $args) {
		$callSites++;
		$parts = array_map('trim', explode(',', $args));
		$second = trim($parts[1] ?? '', "'\" \n\t");
		if (in_array($second, Degradation::STATES, true)) {
			$transposed[] = $file->getFilename() . ': ' . $second;
		}
	}
}
ok('every declaration in src/ was found by the scan', $callSites >= 8);
ok(
	'and none passes a state where the reason belongs: ' . implode(', ', $transposed),
	$transposed === [],
);
// #endregion

// #region P18: the operations terminal's parser
$parsed = CommandLine::parse('en pathauto token');
ok('a plain operation parses', $parsed['ok'] === true && $parsed['op'] === 'en');
ok('and is an operation rather than a package', $parsed['kind'] === 'operation');
ok(
	'every drush spelling of one operation resolves to it',
	CommandLine::parse('cache:rebuild')['op'] === 'cr' &&
		CommandLine::parse('cache-rebuild')['op'] === 'cr' &&
		CommandLine::parse('pm:install token')['op'] === 'en' &&
		CommandLine::parse('core:status')['op'] === 'status',
);
ok(
	'flags are separated from arguments',
	CommandLine::parse('en token -y --quiet')['args'] === ['token'],
);
ok('and carries its arguments', $parsed['args'] === ['pathauto', 'token']);
ok('and the registry decides whether it writes', $parsed['writes'] === true);

$prefixed = CommandLine::parse('drush cr');
ok('a leading drush is accepted and ignored', $prefixed['ok'] === true && $prefixed['op'] === 'cr');
ok(
	'a vendor-path drush is too, which is what muscle memory types',
	CommandLine::parse('./vendor/bin/drush status')['op'] === 'status',
);

$composer = CommandLine::parse('composer require drupal/token:^1.17');
ok('composer require parses as an install rather than a refusal', $composer['ok'] === true);
ok('and is a package operation', $composer['kind'] === 'package' && $composer['verb'] === 'add');
ok(
	'with the constraint split off the name',
	$composer['packages'] === [['name' => 'drupal/token', 'constraint' => '^1.17']],
);
ok(
	'a bare npm install parses to the same intent',
	CommandLine::parse('npm i lodash')['verb'] === 'add',
);
ok(
	'bun resolves against the npm registry',
	CommandLine::parse('bun add lodash')['manager'] === 'npm',
);
ok(
	'an npm scoped name keeps its scope and splits on the LAST @',
	CommandLine::normalisePackage('@scope/pkg@^1.2') === [
		'name' => '@scope/pkg',
		'constraint' => '^1.2',
	],
);
ok(
	'composer install with no package is refused, because there is no lock to restore into',
	CommandLine::parse('composer install')['ok'] === false,
);
ok(
	'a package install is sliced, since a fetch and unpack is not one invocation',
	$composer['sliced'] === true,
);
foreach (['php', 'eval', 'sql', 'ssh'] as $name) {
	ok(
		sprintf('%s is refused as arbitrary execution rather than run', $name),
		(CommandLine::parse($name . ' whatever')['refusal'] ?? null) === $name,
	);
}

$unknown = CommandLine::parse('nonsense');
ok(
	'an unknown operation is not a refusal',
	$unknown['ok'] === false && !isset($unknown['refusal']),
);
ok('and names what IS available', str_contains($unknown['error'], 'status'));
ok('an empty line is refused rather than parsed', CommandLine::parse('   ')['ok'] === false);
ok('a bare drush with nothing after it is too', CommandLine::parse('drush')['ok'] === false);

// str_getcsv() is the usual shortcut and gets exactly this wrong: a quote inside a bare word opens
// a field and swallows the rest of the line
ok(
	'a quote inside a word is literal and does not swallow the line',
	CommandLine::words('en my"module other') === ['en', 'my"module', 'other'],
);
ok(
	'a quoted argument keeps its spaces',
	CommandLine::words('cim "my config dir" x') === ['cim', 'my config dir', 'x'],
);
ok(
	'an empty quoted argument is still an argument',
	CommandLine::words("en '' x") === ['en', '', 'x'],
);

ok(
	'a sliced operation says it is driven in the background rather than run inline',
	str_contains(CommandLine::plan(CommandLine::parse('cr')), 'background'),
);
ok(
	'a read-only one does not claim to write',
	!str_contains(CommandLine::plan(CommandLine::parse('status')), 'It writes.'),
);
// #endregion

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
