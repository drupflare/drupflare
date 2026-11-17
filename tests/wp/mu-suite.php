<?php

/**
 * @file
 * Drives the must-use plugin in src/Wp/mu against real WordPress plugin, error and REST response code.
 *
 * Needs PHP and a WordPress checkout. The tree comes from WORDPRESS_SRC (default
 * ../../../worker/wordpress-src), the host helpers from WORKER_SRC (default ../../../worker) and
 * the plugin directory from DRUPFLARE_MU_DIR (default ../../src/Wp/mu).
 *
 * Usage:
 *   php tests/wp/mu-suite.php
 */

use Drupflare\Wp\BridgeWpdb;

$wpRoot = rtrim(getenv('WORDPRESS_SRC') ?: __DIR__ . '/../../../worker/wordpress-src', '/');
$workerRoot = rtrim(getenv('WORKER_SRC') ?: __DIR__ . '/../../../worker', '/');
$muDir = rtrim(getenv('DRUPFLARE_MU_DIR') ?: __DIR__ . '/../../src/Wp/mu', '/');
$helpers = $workerRoot . '/src/site/php/host-helpers.php';
$siteHealth = $wpRoot . '/wp-admin/includes/class-wp-site-health.php';

foreach (
	[
		$wpRoot . '/wp-includes/class-wpdb.php',
		$wpRoot . '/wp-includes/formatting.php',
		$wpRoot . '/wp-includes/version.php',
		$siteHealth,
		$helpers,
		$muDir . '/drupflare-host.php',
	]
	as $needed
) {
	if (!is_file($needed)) {
		fwrite(STDERR, "missing $needed; set WORDPRESS_SRC, WORKER_SRC and DRUPFLARE_MU_DIR\n");
		exit(2);
	}
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$pass = 0;
$fail = 0;

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

function same(string $label, mixed $expected, mixed $actual): void
{
	ok(
		$label,
		$expected === $actual,
		'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true),
	);
}

function section(string $name): void
{
	echo "\n$name\n";
}

#region wordpress stubs
// real: plugin.php, class-wp-error.php, the two rest response classes, compat.php, utf8.php, formatting.php,
// functions.php, class-wpdb.php. stubbed below: options, capability and rest registration
define('ABSPATH', $wpRoot . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/mu-suite-no-content');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('DB_USER', 'cfw');
define('DB_PASSWORD', 'cfw');
define('DB_NAME', 'wordpress');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_SALT', 'suite-salt-value');

require ABSPATH . WPINC . '/version.php';

function __($text, $domain = 'default')
{
	return $text;
}

function wp_load_translations_early() {}

function is_utf8_charset($charset = null)
{
	return true;
}

function is_multisite()
{
	return false;
}

function absint($value)
{
	return abs((int) $value);
}

function wp_doing_ajax()
{
	return true;
}

function is_wp_error($thing)
{
	return $thing instanceof WP_Error;
}

$GLOBALS['options'] = [
	'siteurl' => 'https://example.test',
	'home' => 'https://example.test',
	'active_plugins' => ['a/a.php', 'b/b.php', 'c/c.php'],
];
$GLOBALS['userCan'] = false;
$GLOBALS['routes'] = [];

function get_option($name, $default = false)
{
	return $GLOBALS['options'][$name] ?? $default;
}

function update_option($name, $value, $autoload = null)
{
	$GLOBALS['options'][$name] = $value;
	return true;
}

function get_bloginfo($show = '')
{
	return $show === 'version' ? $GLOBALS['wp_version'] : '';
}

function wp_get_themes()
{
	return ['twentytwentysix' => new stdClass(), 'custom' => new stdClass()];
}

function current_user_can($capability)
{
	return $capability === 'manage_options' && $GLOBALS['userCan'];
}

function rest_authorization_required_code()
{
	return 401;
}

function register_rest_route($namespace, $route, $args = [])
{
	$GLOBALS['routes'][] = compact('namespace', 'route', 'args');
	return true;
}

require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/class-wp-http-response.php';
require ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/class-wpdb.php';
#endregion

#region fake host and drop-in
$GLOBALS['hostInstalled'] = true;

function vrzno_env(string $name): mixed
{
	if ($name !== 'cfwMysqlExec' || !$GLOBALS['hostInstalled']) {
		return null;
	}
	return static function (string $json): mixed {
		$request = json_decode($json, true);
		$kind = str_starts_with($request['sql'], 'SELECT VERSION()')
			? [
				'ok' => true,
				'kind' => 'rows',
				'columns' => ['VERSION()'],
				'rows' => [['8.0.99-suite']],
			]
			: ['ok' => true, 'kind' => 'none'];
		return json_encode($kind);
	};
}

function pw_encode(mixed $value): mixed
{
	return $value;
}

function pw_decode(mixed $value): mixed
{
	return $value;
}

require $helpers;
require __DIR__ . '/../../src/Wp/db.php';
#endregion

function includeQuietly(string $file): string
{
	ob_start();
	try {
		require_once $file;
	} finally {
		$output = (string) ob_get_clean();
	}
	return $output;
}

/** the test ids get_tests() lists in 'direct' and 'async', read from the real source */
function coreTestKeys(string $source): array
{
	$start = strpos($source, 'public static function get_tests()');
	$end = strpos($source, '$tests = apply_filters( \'site_status_tests\'', $start);
	$body = substr($source, $start, $end - $start);
	$split = strpos($body, "'async'  => array(");
	$keys = ['direct' => [], 'async' => []];
	foreach (
		['direct' => substr($body, 0, $split), 'async' => substr($body, $split)]
		as $kind => $part
	) {
		preg_match_all("/^\t{4}'(\w+)'\s*=> array\(/m", $part, $found);
		$keys[$kind] = $found[1];
	}
	return $keys;
}

/** runs one filtered test, or answers an empty result when it is not callable */
function runTest(array $tests, string $key): array
{
	$test = $tests['direct'][$key]['test'] ?? null;
	return is_callable($test)
		? $test()
		: ['label' => '', 'description' => '', 'status' => '', 'test' => ''];
}

function fixtureTests(array $keys): array
{
	$tests = ['direct' => [], 'async' => []];
	foreach ($keys['direct'] as $key) {
		$tests['direct'][$key] = ['label' => "core $key", 'test' => $key];
	}
	foreach ($keys['async'] as $key) {
		$tests['async'][$key] = [
			'label' => "core $key",
			'test' => "https://x.test/$key",
			'has_rest' => true,
		];
	}
	$tests['async']['authorization_header'] = [
		'label' => 'core authorization_header',
		'test' => 'u',
	];
	$tests['async']['page_cache'] = [
		'label' => 'core page_cache',
		'test' => 'u',
		'has_rest' => true,
	];
	$tests['direct']['persistent_object_cache'] = [
		'label' => 'core persistent_object_cache',
		'test' => 'persistent_object_cache',
	];
	return $tests;
}

#region loading
section('loading');
$output = includeQuietly($muDir . '/drupflare-host.php');
same('the plugin file produces zero bytes of output when included', '', $output);
same(
	'DISALLOW_FILE_MODS is defined true',
	true,
	defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS,
);
same('DISABLE_WP_CRON is defined true', true, defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
same(
	'the boot function hangs on plugins_loaded',
	10,
	has_action('plugins_loaded', 'drupflare_host_boot'),
);
same('no site health filter runs before plugins_loaded', false, has_filter('site_status_tests'));
same('no debug filter runs before plugins_loaded', false, has_filter('debug_information'));
same('no rest hook runs before plugins_loaded', false, has_action('rest_api_init'));

$siblings = array_map('basename', glob($muDir . '/*.php') ?: []);
same(
	'only the loader sits at the top level where wordpress would load it',
	['drupflare-host.php'],
	$siblings,
);

$guard = <<<'PHP'
define('ABSPATH', '/x/');
define('DISALLOW_FILE_MODS', false);
define('DISABLE_WP_CRON', false);
function add_action() {}
function add_filter() {}
require getenv('MU_FILE');
echo var_export(DISALLOW_FILE_MODS, true), ',', var_export(DISABLE_WP_CRON, true);
PHP;
$run = proc_open(
	[PHP_BINARY, '-r', $guard],
	[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
	$pipes,
	null,
	['MU_FILE' => $muDir . '/drupflare-host.php'],
);
$childOut = stream_get_contents($pipes[1]);
proc_close($run);
same('a constant set earlier in wp-config keeps its value', 'false,false', $childOut);

foreach (['wp_version_check', 'wp_update_plugins', 'wp_update_themes'] as $updateHook) {
	add_action($updateHook, $updateHook);
	add_action($updateHook, 'another_callback');
}

foreach (['_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes'] as $inlineCheck) {
	add_action('admin_init', $inlineCheck);
}

do_action('plugins_loaded');
#endregion

#region registrations
section('registrations');
foreach (['_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes'] as $inlineCheck) {
	same(
		"the inline $inlineCheck on admin_init is removed",
		false,
		has_action('admin_init', $inlineCheck),
	);
}
foreach (['wp_version_check', 'wp_update_plugins', 'wp_update_themes'] as $updateHook) {
	same("the core $updateHook callback is removed", false, has_action($updateHook, $updateHook));
	same(
		"another callback on $updateHook is left alone",
		10,
		has_action($updateHook, 'another_callback'),
	);
}
same(
	'site_status_tests is registered at priority 10',
	10,
	has_filter('site_status_tests', 'drupflare_host_site_status_tests'),
);
same(
	'debug_information is registered at priority 10',
	10,
	has_filter('debug_information', 'drupflare_host_debug_information'),
);
same(
	'rest_api_init registers the route',
	10,
	has_action('rest_api_init', 'drupflare_host_register_routes'),
);
same(
	'the cron lock delete records the last run',
	10,
	has_action('delete_transient_doing_cron', 'drupflare_host_record_cron'),
);
#endregion

#region site health tests
section('site health tests');
$source = file_get_contents($siteHealth);
$keys = coreTestKeys($source);
ok('the core test list was parsed', count($keys['direct']) > 15 && count($keys['async']) >= 4);
$notes = drupflare_host_test_notes();
$missing = array_values(
	array_filter(
		array_keys($notes),
		static fn(string $key): bool => !str_contains($source, "'$key'"),
	),
);
same('every replaced test id exists in this WordPress version', [], $missing);

$before = fixtureTests($keys);
$after = apply_filters('site_status_tests', $before);
foreach (array_keys($notes) as $key) {
	ok(
		"$key is a direct test with a callable",
		isset($after['direct'][$key]) && is_callable($after['direct'][$key]['test']),
	);
	ok("$key left the async list", !isset($after['async'][$key]));
}
same(
	'https_status stays untouched',
	$before['async']['https_status'],
	$after['async']['https_status'],
);
same(
	'dotorg_communication stays untouched',
	$before['async']['dotorg_communication'],
	$after['async']['dotorg_communication'],
);
same(
	'authorization_header stays untouched',
	$before['async']['authorization_header'],
	$after['async']['authorization_header'],
);
same(
	'wordpress_version stays untouched',
	$before['direct']['wordpress_version'],
	$after['direct']['wordpress_version'],
);
same(
	'an unrelated direct test keeps its position',
	array_search('wordpress_version', array_keys($after['direct'])),
	0,
);
same(
	'a filter run on a non-array returns it unchanged',
	'x',
	apply_filters('site_status_tests', 'x'),
);
same(
	'a list without the tests comes back the same',
	['direct' => [], 'async' => []],
	apply_filters('site_status_tests', ['direct' => [], 'async' => []]),
);

foreach (array_keys($notes) as $key) {
	$result = runTest($after, $key);
	same(
		"$key result has the site health keys",
		['label', 'status', 'badge', 'description', 'actions', 'test'],
		array_keys($result),
	);
	same("$key reports good", 'good', $result['status']);
	same("$key names itself", $key, $result['test']);
	ok(
		"$key explains itself in its description",
		str_contains($result['description'], '<p>') && strlen($result['description']) > 80,
	);
	same(
		"$key text is plain ascii",
		1,
		preg_match('/^[\x20-\x7e\n]*$/', $result['label'] . $result['description']),
	);
}
$sql = runTest($after, 'sql_server');
ok('sql_server shows the declared version', str_contains($sql['description'], '8.0.99-suite'));
ok('sql_server names the SQLite backing', str_contains($sql['description'], 'SQLite'));
$loop = runTest($after, 'loopback_requests');
ok(
	'loopback says the site cannot call itself',
	str_contains($loop['description'], 'cannot send requests to itself'),
);
$rest = runTest($after, 'rest_availability');
ok(
	'rest availability says the site cannot call itself',
	str_contains($rest['description'], 'cannot send requests to itself'),
);
$updates = runTest($after, 'background_updates');
ok(
	'updates say they are host operations',
	str_contains($updates['description'], 'host operations'),
);
$cron = runTest($after, 'scheduled_events');
ok(
	'scheduled events say the platform drives cron',
	str_contains($cron['description'], 'driven by the platform'),
);

$partial = apply_filters('site_status_tests', [
	'direct' => ['wordpress_version' => $before['direct']['wordpress_version']],
	'async' => [],
]);
same(
	'a test absent from the list is not added',
	['wordpress_version'],
	array_keys($partial['direct']),
);
#endregion

#region debug information
section('debug information');
$info = [
	'wp-core' => ['label' => 'WordPress', 'fields' => []],
	'wp-database' => [
		'label' => 'Database',
		'fields' => ['server_version' => ['label' => 'Server version', 'value' => '8.0.99-suite']],
	],
];
$debug = apply_filters('debug_information', $info);
same(
	'the drupflare section is added after the others',
	['wp-core', 'wp-database', 'drupflare'],
	array_keys($debug),
);
same('the wp-core section is untouched', $info['wp-core'], $debug['wp-core']);
$fields = $debug['drupflare']['fields'];
same(
	'the drupflare section has the agreed fields',
	[
		'host_function',
		'host_function_installed',
		'server_version',
		'wpdb_class',
		'db_dropin_is_drupflare',
		'disallow_file_mods',
		'disable_wp_cron',
		'php_version',
	],
	array_keys($fields),
);
same('host function name', 'cfwMysqlExec', $fields['host_function']['value']);
same('host function installed', 'yes', $fields['host_function_installed']['value']);
same('declared server version', '8.0.99-suite', $fields['server_version']['value']);
same('wpdb class', BridgeWpdb::class, $fields['wpdb_class']['value']);
same('the drop-in is ours', 'yes', $fields['db_dropin_is_drupflare']['value']);
same('DISALLOW_FILE_MODS value', 'true', $fields['disallow_file_mods']['value']);
same('DISABLE_WP_CRON value', 'true', $fields['disable_wp_cron']['value']);
same('php version', PHP_VERSION, $fields['php_version']['value']);
ok(
	'every value is a string',
	array_filter($fields, static fn(array $f): bool => !is_string($f['value'])) === [],
);
$flat = json_encode($debug['drupflare']);
ok('no salt in the section', !str_contains($flat, 'suite-salt-value'));
ok(
	'no absolute paths in the section',
	!str_contains($flat, $wpRoot) && !str_contains($flat, WP_CONTENT_DIR),
);
same(
	'wp-database server version names the platform',
	'8.0.99-suite (SQLite-backed, behind a MySQL translator)',
	$debug['wp-database']['fields']['server_version']['value'],
);
same(
	'a filter run on a non-array returns it unchanged',
	'x',
	apply_filters('debug_information', 'x'),
);

$GLOBALS['hostInstalled'] = false;
$real = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new stdClass();
$other = apply_filters('debug_information', [])['drupflare']['fields'];
same('a foreign wpdb is reported', 'stdClass', $other['wpdb_class']['value']);
same('a foreign wpdb is not ours', 'no', $other['db_dropin_is_drupflare']['value']);
same('a foreign wpdb declares no version', 'unknown', $other['server_version']['value']);
same('a missing host function reads no', 'no', $other['host_function_installed']['value']);
$GLOBALS['wpdb'] = $real;
$GLOBALS['hostInstalled'] = true;
#endregion

#region status
section('status');
$status = drupflare_host_status();
same(
	'status keys',
	[
		'wordpress_version',
		'php_version',
		'db_class',
		'db_dropin_is_drupflare',
		'db_server_version',
		'plugins_active',
		'themes_installed',
		'last_cron',
		'siteurl',
		'home',
	],
	array_keys($status),
);
same('wordpress version', $wp_version, $status['wordpress_version']);
same('php version', PHP_VERSION, $status['php_version']);
same('db class', BridgeWpdb::class, $status['db_class']);
same('drop-in flag is a bool', true, $status['db_dropin_is_drupflare']);
same('declared version', '8.0.99-suite', $status['db_server_version']);
same('plugins active is an int', 3, $status['plugins_active']);
same('themes installed is an int', 2, $status['themes_installed']);
same('last cron is null before any run', null, $status['last_cron']);
same('siteurl', 'https://example.test', $status['siteurl']);
same('home', 'https://example.test', $status['home']);

do_action('delete_transient_doing_cron', 'doing_cron');
$after = drupflare_host_status()['last_cron'];
ok('last cron is an int after a run', is_int($after) && abs($after - time()) <= 2);

add_filter('drupflare_host_status', static fn(array $s): array => $s + ['extra' => 1]);
same('the status filter can add a key', 1, drupflare_host_status()['extra']);
remove_all_filters('drupflare_host_status');

$GLOBALS['options']['active_plugins'] = 'garbage';
same('a corrupt active_plugins reads as zero', 0, drupflare_host_status()['plugins_active']);
#endregion

#region rest
section('rest');
do_action('rest_api_init');
same('one route is registered', 1, count($GLOBALS['routes']));
$route = $GLOBALS['routes'][0];
same('namespace', 'drupflare/v1', $route['namespace']);
same('path', '/status', $route['route']);
same('the route answers GET only', 'GET', $route['args']['methods']);
same(
	'the callback is the status handler',
	'drupflare_host_rest_status',
	$route['args']['callback'],
);
$permission = $route['args']['permission_callback'];

$GLOBALS['userCan'] = false;
same('permission refuses without manage_options', false, $permission());
$denied = $route['args']['callback']();
ok('the callback itself refuses without the capability', $denied instanceof WP_Error);
same('the refusal code', 'drupflare_forbidden', $denied->get_error_code());
same('the refusal status', ['status' => 401], $denied->get_error_data());

$GLOBALS['userCan'] = true;
same('permission allows manage_options', true, $permission());
$response = $route['args']['callback']();
ok('the callback returns a rest response', $response instanceof WP_REST_Response);
$headers = $response->get_headers();
ok('the response sends no-store', str_contains($headers['Cache-Control'] ?? '', 'no-store'));
same('the response status', 200, $response->get_status());
same('the response carries the same array', drupflare_host_status(), $response->get_data());
$GLOBALS['userCan'] = false;
#endregion

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
