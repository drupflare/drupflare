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

function _canonical_charset($charset)
{
	return 'UTF-8';
}

function wp_kses_normalize_entities($text, $context = 'html')
{
	return $text;
}

function network_home_url($path = '')
{
	return 'https://www.example.test' . $path;
}

function wp_parse_url($url, $component = -1)
{
	return parse_url($url, $component);
}

function wp_check_filetype($filename)
{
	$types = ['pdf' => 'application/pdf', 'txt' => 'text/plain'];
	$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
	return ['ext' => isset($types[$ext]) ? $ext : false, 'type' => $types[$ext] ?? false];
}

function register_rest_route($namespace, $route, $args = [])
{
	$GLOBALS['routes'][] = compact('namespace', 'route', 'args');
	return true;
}

$GLOBALS['transients'] = [];
$GLOBALS['dies'] = [];

function get_site_transient($name)
{
	return $GLOBALS['transients'][$name] ?? false;
}

function admin_url($path = '')
{
	return 'https://example.test/wp-admin/' . $path;
}

function wp_die($message = '', $title = '', $args = [])
{
	$GLOBALS['dies'][] = compact('message', 'title', 'args');
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

$GLOBALS['hostFns'] = [];

function vrzno_env(string $name): mixed
{
	if (isset($GLOBALS['hostFns'][$name])) {
		return $GLOBALS['hostFns'][$name];
	}
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
// the pack mounts the neutral classes under lib/; the checkout keeps them one level above
define('DRUPFLARE_HOST_LIB', dirname($muDir, 2));
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
define('DRUPFLARE_HOST_LIB', getenv('HOST_LIB'));
function add_action() {}
function add_filter() {}
function remove_action() {}
require getenv('MU_FILE');
echo var_export(DISALLOW_FILE_MODS, true), ',', var_export(DISABLE_WP_CRON, true);
PHP;
$run = proc_open(
	[PHP_BINARY, '-r', $guard],
	[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
	$pipes,
	null,
	['MU_FILE' => $muDir . '/drupflare-host.php', 'HOST_LIB' => dirname($muDir, 2)],
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
		"the inline $inlineCheck on admin_init is left to WordPress",
		10,
		has_action('admin_init', $inlineCheck),
	);
}
foreach (['wp_version_check', 'wp_update_plugins', 'wp_update_themes'] as $updateHook) {
	same(
		"the core $updateHook callback is left to WordPress",
		10,
		has_action($updateHook, $updateHook),
	);
	same(
		"another callback on $updateHook is left alone",
		10,
		has_action($updateHook, 'another_callback'),
	);
}
same(
	'pre_http_request runs the host request path ahead of the default priority',
	5,
	has_filter('pre_http_request', 'drupflare_http_pre_request'),
);
same(
	'wp_image_editors offers the host editor ahead of the default priority',
	5,
	has_filter('wp_image_editors', 'drupflare_image_editors'),
);
same(
	'pre_wp_mail answers through the host ahead of the default priority',
	5,
	has_filter('pre_wp_mail', 'drupflare_mail_pre_wp_mail'),
);
same(
	'the mail test is added to site health at priority 10',
	10,
	has_filter('site_status_tests', 'drupflare_mail_site_status_tests'),
);
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
ok(
	'wordpress_version is replaced by the core updates test',
	!isset($after['direct']['wordpress_version']) &&
		is_callable($after['direct']['drupflare_core_updates']['test'] ?? null),
);
same(
	'an unrelated direct test keeps its position',
	array_values(array_diff(array_keys($before['direct']), ['wordpress_version']))[0],
	array_key_first($after['direct']),
);
same(
	'a filter run on a non-array returns it unchanged',
	'x',
	apply_filters('site_status_tests', 'x'),
);
same(
	'a list without the replaced tests gains only the mail and core updates tests',
	['drupflare_mail', 'drupflare_core_updates'],
	array_keys(apply_filters('site_status_tests', ['direct' => [], 'async' => []])['direct']),
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
foreach (['loopback_requests', 'rest_availability'] as $own) {
	$kind = isset($before['direct'][$own]) ? 'direct' : 'async';
	same(
		"$own is left to WordPress, which runs it through the request filter",
		$before[$kind][$own],
		$after[$kind][$own],
	);
	ok("$own has no note of its own", !isset($notes[$own]));
}
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
	'a replaced test absent from the list is not added, and the core version test is dropped',
	['drupflare_mail', 'drupflare_core_updates'],
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
		'outbound_requests',
		'image_editor',
		'mail',
	],
	array_keys($fields),
);
same(
	'mail says it is unavailable when no host answers',
	'not available in this deployment',
	$fields['mail']['value'],
);
same(
	'outbound requests say it is unavailable when no host answers',
	'not available in this deployment',
	$fields['outbound_requests']['value'],
);
same(
	'the image editor says it is unavailable when no host answers',
	'none, the image host is not available in this deployment',
	$fields['image_editor']['value'],
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
		'mail',
	],
	array_keys($status),
);
same('mail status is null without the host snapshot', null, $status['mail']);
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

#region mail
section('mail');
$GLOBALS['mailSeen'] = [];
$GLOBALS['mailReply'] = ['ok' => true, 'queued' => 1, 'transport' => 'api'];
$GLOBALS['hostFns']['cfwMail'] = static function (string $json): string {
	$GLOBALS['mailSeen'][] = json_decode($json, true);
	return json_encode($GLOBALS['mailReply']);
};
$GLOBALS['statsReply'] = [
	'mailTransport' => 'api',
	'mailQueue' => 2,
	'mailFailed' => null,
];
$GLOBALS['hostFns']['cfwServeStats'] = static fn(): string => json_encode($GLOBALS['statsReply']);
$GLOBALS['failed'] = [];
$GLOBALS['succeeded'] = 0;
add_action('wp_mail_failed', static function ($error) {
	$GLOBALS['failed'][] = $error;
});
add_action('wp_mail_succeeded', static function () {
	$GLOBALS['succeeded']++;
});

function sendMail(array $atts): array
{
	$GLOBALS['mailSeen'] = [];
	$GLOBALS['failed'] = [];
	$result = drupflare_mail_pre_wp_mail(null, $atts + ['headers' => '', 'attachments' => []]);
	return [$result, $GLOBALS['mailSeen'][0] ?? null];
}

[$result, $seen] = sendMail([
	'to' => 'a@example.org',
	'subject' => 'Hello',
	'message' => 'Body text',
]);
same('a plain message is accepted', true, $result);
same('the host got the recipient', 'a@example.org', $seen['to']);
same(
	'the sender defaults to wordpress at the site host without www',
	'WordPress <wordpress@example.test>',
	$seen['from'],
);
same(
	'the subject and plain body arrive',
	['Hello', 'Body text', null],
	[$seen['subject'], $seen['text'], $seen['html']],
);
same('the message asks for the default attempts', 3, $seen['retry']);
same('no attachments are sent when there are none', [], $seen['attachments']);
same('wp_mail_succeeded fired once', 1, $GLOBALS['succeeded']);
same('wp_mail_failed did not fire', [], $GLOBALS['failed']);

$GLOBALS['mailSeen'] = [];
same(
	'another filter that already answered wins',
	false,
	drupflare_mail_pre_wp_mail(false, ['to' => 'a@example.org']),
);
same('and the host is not called for it', [], $GLOBALS['mailSeen']);

[$result, $seen] = sendMail([
	'to' => ['A One <a@example.org>', 'b@example.org'],
	'subject' => 'Both',
	'message' => 'x',
	'headers' => [
		'From: "The Site" <site@example.test>',
		'Reply-To: Help Desk <help@example.test>',
		'Cc: c@example.org, "Last, First" <d@example.org>',
		'Bcc: e@example.org',
		'In-Reply-To: <abc@example.test>',
		'References: <abc@example.test>',
		'X-Custom: dropped',
	],
]);
same(
	'names are kept and addresses are joined',
	'A One <a@example.org>, b@example.org',
	$seen['to'],
);
same('a From header sets the sender and its name', 'The Site <site@example.test>', $seen['from']);
same('Reply-To is passed', 'Help Desk <help@example.test>', $seen['replyTo']);
same(
	'Cc splits on commas as wp_mail does, so a name with a comma loses its first half, and Bcc passes',
	['Cc' => 'c@example.org, First <d@example.org>', 'Bcc' => 'e@example.org'],
	array_intersect_key($seen['headers'], ['Cc' => 1, 'Bcc' => 1]),
);
same(
	'threading headers pass',
	['<abc@example.test>', '<abc@example.test>'],
	[$seen['headers']['In-Reply-To'], $seen['headers']['References']],
);
ok(
	'another custom header is dropped, not sent as something else',
	!isset($seen['headers']['X-Custom']),
);

[$result, $seen] = sendMail([
	'to' => 'a@example.org',
	'subject' => "Line\r\nBcc: evil@example.net",
	'message' => 'x',
	'headers' => "From: Site <site@example.test>\r\n",
]);
ok(
	'a line break in the subject cannot start a header',
	$seen !== null &&
		!str_contains($seen['subject'], "\n") &&
		!str_contains($seen['subject'], "\r"),
);
[$result, $seen] = sendMail([
	'to' => 'a@example.org',
	'subject' => 's',
	'message' => 'x',
	'headers' => 'From: Mallory',
]);
ok('an invalid From address is refused before the host', $result === false && $seen === null);
same(
	'the refusal reaches wp_mail_failed as a WP_Error',
	'wp_mail_failed',
	$GLOBALS['failed'][0]->get_error_code(),
);

[$result, $seen] = sendMail([
	'to' => 'a@example.org',
	'subject' => 'Html',
	'message' => '<p>Hello <b>there</b></p>',
	'headers' => 'Content-Type: text/html; charset=UTF-8',
]);
same(
	'an html header sends the markup and a text version',
	['<p>Hello <b>there</b></p>', 'Hello there'],
	[$seen['html'], $seen['text']],
);
add_filter('wp_mail_content_type', $htmlType = static fn() => 'text/html');
[$result, $seen] = sendMail(['to' => 'a@example.org', 'subject' => 's', 'message' => '<i>x</i>']);
same('the content type filter is honored', '<i>x</i>', $seen['html']);
remove_filter('wp_mail_content_type', $htmlType);

[$result, $seen] = sendMail([
	'to' => 'not an address, ok@example.org',
	'subject' => 's',
	'message' => 'x',
]);
same('an invalid recipient is skipped and the rest are sent', 'ok@example.org', $seen['to']);
[$result, $seen] = sendMail(['to' => 'nobody', 'subject' => 's', 'message' => 'x']);
ok('no valid recipient fails without calling the host', $result === false && $seen === null);
same(
	'the message names the cause',
	'You must provide at least one recipient email address.',
	$GLOBALS['failed'][0]->get_error_message(),
);
same('the error data carries the mail', 'nobody', $GLOBALS['failed'][0]->get_error_data()['to'][0]);

$GLOBALS['mailReply'] = ['ok' => false, 'error' => 'mail is switched off by MAIL_TRANSPORT=off'];
$GLOBALS['succeeded'] = 0;
[$result, $seen] = sendMail(['to' => 'a@example.org', 'subject' => 's', 'message' => 'x']);
same('a host refusal returns false', false, $result);
same(
	'the host reason is the error',
	'Mail was not accepted: mail is switched off by MAIL_TRANSPORT=off',
	$GLOBALS['failed'][0]->get_error_message(),
);
same('wp_mail_failed fired exactly once', 1, count($GLOBALS['failed']));
same('wp_mail_succeeded stayed quiet', 0, $GLOBALS['succeeded']);
$GLOBALS['mailReply'] = ['ok' => true, 'queued' => 2, 'transport' => 'api'];

$dir = sys_get_temp_dir() . '/mu-mail-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/report.pdf', '%PDF-1.4 body');
file_put_contents($dir . '/big.txt', str_repeat('x', 700001));
[$result, $seen] = sendMail([
	'to' => 'a@example.org',
	'subject' => 's',
	'message' => 'x',
	'attachments' => [$dir . '/report.pdf', 'Notes' => $dir . '/report.pdf'],
]);
same(
	'attachments carry a name, a type and base64 bytes',
	[
		[
			'filename' => 'report.pdf',
			'type' => 'application/pdf',
			'b64' => base64_encode('%PDF-1.4 body'),
		],
		[
			'filename' => 'Notes',
			'type' => 'application/pdf',
			'b64' => base64_encode('%PDF-1.4 body'),
		],
	],
	$seen['attachments'],
);
[$result, $seen] = sendMail([
	'to' => 'a@example.org',
	'subject' => 's',
	'message' => 'x',
	'attachments' => [$dir . '/big.txt'],
]);
ok('attachments over the cap fail before the host', $result === false && $seen === null);
ok(
	'the cap is named in the error',
	str_contains($GLOBALS['failed'][0]->get_error_message(), '700000-byte limit'),
);
[$result, $seen] = sendMail([
	'to' => 'a@example.org',
	'subject' => 's',
	'message' => 'x',
	'attachments' => [$dir . '/missing.pdf'],
]);
ok('a missing attachment fails before the host', $result === false && $seen === null);
same(
	'and says which file',
	'attachment is not readable: missing.pdf',
	$GLOBALS['failed'][0]->get_error_message(),
);
[$result, $seen] = sendMail([
	'to' => 'a@example.org',
	'subject' => 's',
	'message' => 'x',
	'embeds' => [$dir . '/report.pdf'],
]);
ok('an embedded image is refused rather than dropped', $result === false && $seen === null);
same(
	'the refusal names embeds',
	'inline images (embeds) are not supported on this host',
	$GLOBALS['failed'][0]->get_error_message(),
);
unlink($dir . '/report.pdf');
unlink($dir . '/big.txt');
rmdir($dir);

$saved = $GLOBALS['hostFns']['cfwMail'];
unset($GLOBALS['hostFns']['cfwMail']);
[$result] = sendMail(['to' => 'a@example.org', 'subject' => 's', 'message' => 'x']);
ok(
	'without the host function nothing is sent and the failure is reported',
	$result === false && count($GLOBALS['failed']) === 1,
);
$GLOBALS['hostFns']['cfwMail'] = $saved;

section('mail status');
$health = drupflare_mail_site_health_test();
same('a working queue is good', 'good', $health['status']);
same(
	'the status reads the host snapshot',
	['api', 2, 0],
	[
		drupflare_mail_host_status()['transport'],
		drupflare_mail_host_status()['queued'],
		drupflare_mail_host_status()['failed'],
	],
);
$GLOBALS['statsReply']['mailFailed'] = [
	'count' => 3,
	'lastError' => 'API 403: <b>no</b>',
	'lastAt' => 1700000000000,
];
$health = drupflare_mail_site_health_test();
same('lost messages are a recommendation', 'recommended', $health['status']);
ok('the count is visible', str_contains($health['label'], '3 accepted messages'));
ok(
	'the last error is visible and escaped',
	str_contains($health['description'], 'API 403: &lt;b&gt;no&lt;/b&gt;'),
);
same('the failure time is in seconds', 1700000000, drupflare_mail_host_status()['last_failed_at']);
same('the status page carries the failure count', 3, drupflare_host_status()['mail']['failed']);
$GLOBALS['userCan'] = true;
$response = $GLOBALS['routes'][0]['args']['callback']();
same('the rest status carries it too', 3, $response->get_data()['mail']['failed']);
$GLOBALS['userCan'] = false;
ok(
	'the debug line shows count and error',
	str_contains(drupflare_mail_debug_value(), '3 failed after acceptance') &&
		str_contains(drupflare_mail_debug_value(), 'last error: API 403'),
);
$GLOBALS['statsReply'] = ['mailTransport' => null, 'mailQueue' => 0, 'mailFailed' => null];
$health = drupflare_mail_site_health_test();
same('no transport is a recommendation that says what to set', 'recommended', $health['status']);
ok(
	'and names the three transports',
	str_contains($health['description'], 'send_email binding') &&
		str_contains($health['description'], 'SMTP'),
);
$listed = drupflare_mail_site_status_tests(['direct' => [], 'async' => []]);
ok(
	'the test is registered as a direct test with a callable',
	is_callable($listed['direct']['drupflare_mail']['test']),
);
unset($GLOBALS['hostFns']['cfwServeStats']);
same('without the host snapshot the status is null', null, drupflare_mail_host_status());
same(
	'and the debug line says so',
	'not available in this deployment',
	drupflare_mail_debug_value(),
);
#endregion

#region uploads
section('uploads');
same(
	'upload_dir is registered ahead of the default priority',
	5,
	has_filter('upload_dir', 'drupflare_uploads_dir'),
);
same(
	'the move filter is registered with four arguments',
	5,
	has_filter('pre_move_uploaded_file', 'drupflare_uploads_pre_move'),
);
same(
	'the size limit filter is registered',
	20,
	has_filter('upload_size_limit', 'drupflare_uploads_size_limit'),
);
$dirs = [
	'path' => '/p/2026/11',
	'subdir' => '/2026/11',
	'basedir' => '/p',
	'baseurl' => 'https://x/u',
];
same('without the file store the directories are left alone', $dirs, drupflare_uploads_dir($dirs));
same('a filter run on a non-array returns it unchanged', 'x', drupflare_uploads_dir('x'));
$GLOBALS['hostFns']['cfwFileWrite'] = static fn(): string => '{"ok":true}';
$GLOBALS['hostFns']['cfwFileRead'] = static fn(): string => '{"ok":true,"b64":""}';
$mapped = drupflare_uploads_dir($dirs);
same(
	'with the file store the base is public://wp-content/uploads and the url is kept',
	['public://wp-content/uploads/2026/11', 'public://wp-content/uploads', 'https://x/u'],
	[$mapped['path'], $mapped['basedir'], $mapped['baseurl']],
);
same(
	'the admin limit is held to the buffer cap',
	10485760,
	drupflare_uploads_size_limit(104857600),
);
same('a smaller limit stays', 1000, drupflare_uploads_size_limit(1000));
same(
	'a decision already made is kept',
	false,
	drupflare_uploads_pre_move(false, [], 'public://a', 'x'),
);
same(
	'a path outside the store is left to WordPress',
	null,
	drupflare_uploads_pre_move(null, ['tmp_name' => '/tmp/a'], '/var/a', 'x'),
);
same(
	'a temp file the parser did not list is left to WordPress',
	null,
	drupflare_uploads_pre_move(
		null,
		['tmp_name' => '/tmp/unlisted'],
		'public://wp-content/uploads/a.png',
		'x',
	),
);
same(
	'the traversal check refuses a climbing path',
	null,
	drupflare_uploads_target('public://wp-content/uploads/../x'),
);
same(
	'and reads a plain one',
	'wp-content/uploads/a.png',
	drupflare_uploads_target('public://wp-content/uploads/a.png'),
);
unset($GLOBALS['hostFns']['cfwFileWrite'], $GLOBALS['hostFns']['cfwFileRead']);
#endregion

#region core updates
section('core updates');
$offerOf = static function (mixed $transient): ?string {
	$GLOBALS['transients']['update_core'] = $transient;
	return drupflare_updates_offer();
};
$upgrade = static fn(string $current, string $response = 'upgrade'): object => (object) [
	'updates' => [(object) ['response' => $response, 'current' => $current]],
];
same('no transient reads as no offer', null, $offerOf(false));
same('a newer upgrade is the offer', '99.0.0', $offerOf($upgrade('99.0.0')));
same('a latest response is no offer', null, $offerOf($upgrade('99.0.0', 'latest')));
same('an upgrade that is not newer is no offer', null, $offerOf($upgrade('1.0.0')));
same('a transient that is not an object is no offer', null, $offerOf('x'));

$text = drupflare_updates_explanation();
ok(
	'the explanation names the version, the constant, the pack and the route',
	str_contains($text, $GLOBALS['wp_version']) &&
		str_contains($text, 'DISALLOW_FILE_MODS') &&
		str_contains($text, 'new WordPress pack') &&
		str_contains($text, '/wp-packages'),
);
ok('the explanation is plain ascii', preg_match('/^[\x20-\x7e]*$/', $text) === 1);
$GLOBALS['transients']['update_core'] = $upgrade('99.0.0');
ok(
	'the explanation names an available version',
	str_contains(drupflare_updates_explanation(), 'WordPress 99.0.0 is available'),
);

$GLOBALS['dies'] = [];
drupflare_updates_screen();
same('an update screen answers once', 1, count($GLOBALS['dies']));
same(
	'an update screen answers 200, since it is information and not an error',
	200,
	$GLOBALS['dies'][0]['args']['response'],
);
ok(
	'an update screen carries the explanation and offers nothing to run',
	str_contains($GLOBALS['dies'][0]['message'], 'Updates are applied by the platform') &&
		!str_contains($GLOBALS['dies'][0]['message'], 'update-core.php') &&
		!stripos($GLOBALS['dies'][0]['message'], '<form'),
);
foreach (['update-core.php', 'update.php', 'plugin-install.php', 'theme-install.php'] as $screen) {
	ok(
		"$screen is answered by the platform notice",
		has_action('load-' . $screen, 'drupflare_updates_screen') !== false,
	);
}

same(
	'a screen the menu hides is answered before core refuses it with a 403',
	10,
	has_action('admin_page_access_denied', 'drupflare_updates_denied'),
);
$GLOBALS['dies'] = [];
$GLOBALS['pagenow'] = 'plugin-install.php';
drupflare_updates_denied();
same('a denied update screen is answered with the explanation', 1, count($GLOBALS['dies']));
$GLOBALS['dies'] = [];
$GLOBALS['pagenow'] = 'edit.php';
drupflare_updates_denied();
same('a denied screen that installs nothing is left to core', 0, count($GLOBALS['dies']));
unset($GLOBALS['pagenow']);

add_action('admin_notices', 'update_nag', 3);
add_action('network_admin_notices', 'update_nag', 3);
drupflare_updates_register();
same(
	'the removal waits for admin_init, when the admin filters have been added',
	10,
	has_action('admin_init', 'drupflare_updates_admin_init'),
);
same(
	"core's own update notice is still there until then",
	3,
	has_action('admin_notices', 'update_nag'),
);
drupflare_updates_admin_init();
same(
	"core's own update notice is removed",
	[false, false],
	[has_action('admin_notices', 'update_nag'), has_action('network_admin_notices', 'update_nag')],
);
same(
	'the platform notice takes its place',
	3,
	has_action('admin_notices', 'drupflare_updates_notice'),
);

$GLOBALS['userCan'] = false;
ob_start();
drupflare_updates_notice();
same('a visitor without manage_options sees no notice', '', ob_get_clean());
$GLOBALS['userCan'] = true;
ob_start();
drupflare_updates_notice();
$notice = (string) ob_get_clean();
ok(
	'an administrator sees the offer and who applies it',
	str_contains($notice, 'WordPress 99.0.0 is available') &&
		str_contains($notice, 'platform applies core updates') &&
		!str_contains($notice, 'update-core.php'),
);
$GLOBALS['transients']['update_core'] = false;
ob_start();
drupflare_updates_notice();
same('with no offer there is no notice', '', ob_get_clean());
$GLOBALS['userCan'] = false;

$tests = drupflare_updates_site_status_tests([
	'direct' => ['wordpress_version' => []],
	'async' => [],
]);
$current = $tests['direct']['drupflare_core_updates']['test']();
same('with no offer the site health test is good', 'good', $current['status']);
$GLOBALS['transients']['update_core'] = $upgrade('99.0.0');
$offered = $tests['direct']['drupflare_core_updates']['test']();
same('with an offer it is recommended, not critical', 'recommended', $offered['status']);
ok(
	'it explains and links to no screen',
	str_contains($offered['description'], '/wp-packages') &&
		!str_contains($offered['description'] . $offered['label'], 'update-core.php'),
);
same('a non-array list is returned unchanged', 'x', drupflare_updates_site_status_tests('x'));
$GLOBALS['transients']['update_core'] = false;
#endregion

#region multisite
section('multisite');
same('nothing defines MULTISITE', false, defined('MULTISITE'));
same('nothing defines WP_ALLOW_MULTISITE', false, defined('WP_ALLOW_MULTISITE'));
same(
	'the network setup screen is refused',
	10,
	has_action('load-network.php', 'drupflare_multisite_refuse'),
);
$GLOBALS['dies'] = [];
drupflare_multisite_refuse();
same('the refusal answers 501', 501, $GLOBALS['dies'][0]['args']['response'] ?? 0);
ok(
	'the refusal says Multisite is not supported and why',
	str_contains($GLOBALS['dies'][0]['message'], 'Multisite is not supported') &&
		str_contains($GLOBALS['dies'][0]['message'], 'own database'),
);
foreach (
	[
		'/wp-signup.php' => true,
		'/blog/wp-activate.php' => true,
		'/wp-admin/network/' => true,
		'/wp-admin/network' => true,
		'/wp-admin/network/sites.php' => true,
		'/wp-admin/network.php' => false,
		'/wp-admin/networks.php' => false,
		'/wp-login.php' => false,
		'/' => false,
	]
	as $path => $expected
) {
	same("entry point check for $path", $expected, drupflare_multisite_entry($path));
}
$_SERVER['REQUEST_URI'] = '/wp-signup.php?new=1';
drupflare_multisite_register();
ok(
	'a request for a network entry point is refused at load',
	has_action('muplugins_loaded', 'drupflare_multisite_refuse') !== false,
);
remove_action('muplugins_loaded', 'drupflare_multisite_refuse');
$_SERVER['REQUEST_URI'] = '/hello/';
drupflare_multisite_register();
same(
	'an ordinary request is not',
	false,
	has_action('muplugins_loaded', 'drupflare_multisite_refuse'),
);
unset($_SERVER['REQUEST_URI']);
#endregion

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
