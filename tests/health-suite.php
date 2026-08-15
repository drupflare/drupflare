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
spl_autoload_register(function (string $class) use ($root): void {
	$prefix = 'Drupal\\drupflare\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$rel = str_replace('\\', '/', substr($class, strlen($prefix)));
	$file = $root . '/src/' . $rel . '.php';
	if (is_file($file)) {
		require_once $file;
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
	'every verdict is one of exactly two values',
	count(
		array_filter(
			ShimRegistry::functions(),
			static fn($e) => $e['verdict'] === ShimRegistry::ROUTE ||
				$e['verdict'] === ShimRegistry::REFUSE,
		),
	) === count(ShimRegistry::functions()),
);
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
		'openssl_sign' => 'asymmetric signing',
		'imagecreatetruecolor' => 'gd',
		'getimagesize' => 'gd header reads',
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
	'routed() and refused() partition the table with nothing left over',
	count(ShimRegistry::routed()) + count(ShimRegistry::refused()) ===
		count(ShimRegistry::functions()),
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
// Source assertions, because this harness runs with no Drupal root and cannot load
// Drupal\Component\Utility\Html. What matters is that the resetter DOES the reset and says so.
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
// THE ONE THAT MATTERS: TRUE sends RouteBuilder back around to wait again, and waiting is what
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
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
