<?php

/**
 * @file
 * Drives the real wpdb class from a WordPress tree through the bridge subclass.
 *
 * Needs PHP and a WordPress checkout, nothing else. The tree comes from WORDPRESS_SRC (default
 * ../../../worker/wordpress-src) and the host helpers from WORKER_SRC (default ../../../worker).
 *
 * Usage:
 *   php tests/wp/wpdb-suite.php
 */

use Drupflare\Wp\Bridge;
use Drupflare\Wp\BridgeWpdb;

$wpRoot = rtrim(getenv('WORDPRESS_SRC') ?: __DIR__ . '/../../../worker/wordpress-src', '/');
$workerRoot = rtrim(getenv('WORKER_SRC') ?: __DIR__ . '/../../../worker', '/');
$helpers = $workerRoot . '/src/site/php/host-helpers.php';

foreach ([$wpRoot . '/wp-includes/class-wpdb.php', $helpers] as $needed) {
	if (!is_file($needed)) {
		fwrite(STDERR, "missing $needed; set WORDPRESS_SRC and WORKER_SRC\n");
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
// real: plugin.php, class-wp-error.php, functions.php, class-wpdb.php. stubbed below: the
// translation, multisite and load.php helpers the real functions reach for
define('ABSPATH', $wpRoot . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wpdb-suite-no-content');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('SAVEQUERIES', true);
define('DB_USER', 'cfw');
define('DB_PASSWORD', 'cfw');
define('DB_NAME', 'wordpress');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_SALT', 'suite-salt');

function __($text, $domain = 'default')
{
	return $text;
}

function wp_load_translations_early() {}

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

require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/class-wpdb.php';

add_filter(
	'wp_die_ajax_handler',
	static fn() => static function ($message): never {
		throw new RuntimeException('wp_die: ' . (is_string($message) ? $message : 'non-string'));
	},
);
#endregion

#region fake host
$GLOBALS['hostInstalled'] = true;
$GLOBALS['hostCalls'] = [];
$GLOBALS['hostHandler'] = static fn(string $sql): array => ['ok' => true, 'kind' => 'none'];
$GLOBALS['hostRaw'] = null;

function vrzno_env(string $name): mixed
{
	if ($name !== 'cfwMysqlExec' || !$GLOBALS['hostInstalled']) {
		return null;
	}
	return static function (string $json): mixed {
		$request = json_decode($json, true);
		$GLOBALS['hostCalls'][] = $request['sql'];
		if ($GLOBALS['hostRaw'] !== null) {
			return $GLOBALS['hostRaw']($request['sql']);
		}
		return json_encode($GLOBALS['hostHandler']($request['sql']));
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
require __DIR__ . '/../../src/Wp/Bridge.php';

function host(callable $handler): void
{
	$GLOBALS['hostHandler'] = $handler;
	$GLOBALS['hostRaw'] = null;
	$GLOBALS['hostCalls'] = [];
}

function hostCalls(): array
{
	return $GLOBALS['hostCalls'];
}

function rows(array $columns, array $rows): array
{
	return ['ok' => true, 'kind' => 'rows', 'columns' => $columns, 'rows' => $rows];
}

function write(int $affected, int $insertId = 0): array
{
	return ['ok' => true, 'kind' => 'write', 'affected' => $affected, 'insertId' => $insertId];
}

function none(): array
{
	return ['ok' => true, 'kind' => 'none'];
}

function failure(
	int $errno,
	string $error,
	string $sqlstate = 'HY000',
	?string $refused = null,
): array {
	$reply = ['ok' => false, 'errno' => $errno, 'sqlstate' => $sqlstate, 'error' => $error];
	if ($refused !== null) {
		$reply['refused'] = $refused;
	}
	return $reply;
}

/** answers one fixed reply to everything except the connect-time session statement */
function fixed(array $reply): callable
{
	return static fn(string $sql): array => str_starts_with($sql, 'SET SESSION') ? none() : $reply;
}

function connect(callable $handler): BridgeWpdb
{
	host($handler);
	$db = new BridgeWpdb('u', 'p', 'n', 'h');
	$GLOBALS['hostCalls'] = [];
	return $db;
}

function dropIn(): void
{
	global $wpdb;
	require __DIR__ . '/../../src/Wp/db.php';
}

function quiet(callable $fn): string
{
	ini_set('error_log', sys_get_temp_dir() . '/wpdb-suite-error.log');
	ob_start();
	try {
		$fn();
	} finally {
		$out = ob_get_clean();
	}
	return (string) $out;
}
#endregion

#region the drop-in
section('drop-in');
host(static function (string $sql): array {
	return str_starts_with($sql, 'SET SESSION') ? none() : rows(['VERSION()'], [['8.0.40-fake']]);
});
dropIn();
ok('db.php sets $wpdb to the subclass', $GLOBALS['wpdb'] instanceof BridgeWpdb);
same('require sends exactly one statement, the sql_mode', 1, count(hostCalls()));
same(
	'sql_mode statement is the one WordPress sent in the capture',
	"SET SESSION sql_mode='NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
	hostCalls()[0] ?? null,
);
same('wpdb is ready after connect', true, $GLOBALS['wpdb']->ready);
same('charset is utf8mb4', 'utf8mb4', $GLOBALS['wpdb']->charset);
same(
	'collation is the 520 collation the capture shows',
	'utf8mb4_unicode_520_ci',
	$GLOBALS['wpdb']->collate,
);
same(
	'get_charset_collate matches the dbDelta text in the capture',
	'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci',
	$GLOBALS['wpdb']->get_charset_collate(),
);
same('is_mysql stays true so WordPress takes its MySQL paths', true, $GLOBALS['wpdb']->is_mysql);
ok('dbh is an object, which site health reads', is_object($GLOBALS['wpdb']->dbh));
same('client_info exists for site health', 'drupflare-bridge', $GLOBALS['wpdb']->dbh->client_info);
#endregion

#region bridge contract
section('bridge contract');
$bridge = new Bridge();

host(static fn() => rows(['a', 'b'], [[1, 'x'], [2.5, null]]));
same(
	'rows reply decodes to the contract shape',
	['ok' => true, 'kind' => 'rows', 'columns' => ['a', 'b'], 'rows' => [[1, 'x'], [2.5, null]]],
	$bridge->exec('SELECT 1'),
);
same('the host saw the statement text unchanged', ['SELECT 1'], hostCalls());

host(static fn() => ['ok' => true, 'kind' => 'rows', 'columns' => ['a'], 'rows' => [['k' => 1]]]);
same('row maps become lists', [[1]], $bridge->exec('SELECT 1')['rows']);

host(static fn() => write(3, 9));
same(
	'write reply decodes to affected and insertId',
	['ok' => true, 'kind' => 'write', 'affected' => 3, 'insertId' => 9],
	$bridge->exec('UPDATE t SET a = 1'),
);

host(static fn() => none());
same('none reply', ['ok' => true, 'kind' => 'none'], $bridge->exec('SET @a = 1'));

host(static fn() => failure(1146, "Table 'wp.t' doesn't exist", '42S02'));
same(
	'error reply keeps the MySQL number, sqlstate and text',
	[
		'ok' => false,
		'errno' => 1146,
		'sqlstate' => '42S02',
		'error' => "Table 'wp.t' doesn't exist",
	],
	$bridge->exec('SELECT * FROM t'),
);

host(static fn() => failure(1235, 'unsupported', 'HY000', 'FOR UPDATE'));
$refused = $bridge->exec('SELECT 1 FOR UPDATE');
same('refusal carries the reason', 'FOR UPDATE', $refused['refused'] ?? null);
same('refusal is an error value, not an exception', false, $refused['ok']);

host(static fn() => ['ok' => false, 'refused' => 'LOCK TABLES']);
same(
	'refusal with no text and no number still has both',
	[
		'ok' => false,
		'errno' => 1105,
		'sqlstate' => 'HY000',
		'error' => 'Statement refused: LOCK TABLES',
		'refused' => 'LOCK TABLES',
	],
	$bridge->exec('LOCK TABLES t WRITE'),
);

host(static fn() => ['ok' => false]);
same(
	'a bare failure gets the generic text',
	'Unknown error from cfwMysqlExec',
	$bridge->exec('x')['error'],
);

foreach (
	[
		'unknown kind' => ['ok' => true, 'kind' => 'banana'],
		'rows without columns' => ['ok' => true, 'kind' => 'rows', 'rows' => []],
		'row that is not a list' => [
			'ok' => true,
			'kind' => 'rows',
			'columns' => ['a'],
			'rows' => [5],
		],
		'cell that is an array' => [
			'ok' => true,
			'kind' => 'rows',
			'columns' => ['a'],
			'rows' => [[[1]]],
		],
		'missing kind' => ['ok' => true],
	]
	as $label => $reply
) {
	host(static fn() => $reply);
	$decoded = $bridge->exec('SELECT 1');
	ok(
		"malformed reply ($label) becomes an error value",
		$decoded['ok'] === false && $decoded['errno'] === 1105,
	);
}

$GLOBALS['hostRaw'] = static fn() => 'not json';
$decoded = $bridge->exec('SELECT 1');
ok(
	'a reply that is not JSON becomes an error value',
	$decoded['ok'] === false && $decoded['error'] !== '',
);
$GLOBALS['hostRaw'] = static fn() => 42;
$decoded = $bridge->exec('SELECT 1');
ok('a reply that is not a string becomes an error value', $decoded['ok'] === false);
$GLOBALS['hostRaw'] = null;

host(static fn() => none());
$decoded = $bridge->exec("SELECT '\xff\xfe'");
same('invalid UTF-8 text is refused before it crosses', 1300, $decoded['errno'] ?? null);
same('the host never saw the invalid statement', [], hostCalls());

$GLOBALS['hostInstalled'] = false;
same('available() is false with no host function', false, $bridge->available());
$decoded = $bridge->exec('SELECT 1');
same('no host function is an error value with a connection errno', 2002, $decoded['errno'] ?? null);
$GLOBALS['hostInstalled'] = true;
same('available() is true once it is installed', true, $bridge->available());
#endregion

#region escaping
section('escaping');
$db = connect(fixed(none()));
$specials = [
	"\0" => '\\0',
	"\n" => '\\n',
	"\r" => '\\r',
	'\\' => '\\\\',
	"'" => "\\'",
	'"' => '\\"',
	"\x1a" => '\\Z',
];
foreach ($specials as $raw => $escaped) {
	same(
		'escapes byte 0x' . bin2hex($raw),
		$escaped,
		$db->remove_placeholder_escape($db->_real_escape($raw)),
	);
}
$bytes = '';
for ($i = 0; $i < 256; $i++) {
	$bytes .= chr($i);
}
same(
	'only the seven special bytes change across all 256 bytes',
	strtr($bytes, $specials),
	$db->remove_placeholder_escape($db->_real_escape($bytes)),
);
same(
	'multi-byte text passes through',
	"h\u{e9}llo \u{65e5}\u{672c}\u{8a9e} \u{1f600}",
	$db->remove_placeholder_escape(
		$db->_real_escape("h\u{e9}llo \u{65e5}\u{672c}\u{8a9e} \u{1f600}"),
	),
);
same('an array escapes to the empty string', '', $db->_real_escape(['x']));
same('an int escapes as text', '42', $db->remove_placeholder_escape($db->_real_escape(42)));
same('a bool true escapes as 1', '1', $db->remove_placeholder_escape($db->_real_escape(true)));
same('a bool false escapes as empty', '', $db->remove_placeholder_escape($db->_real_escape(false)));
same('a float escapes as text', '1.5', $db->remove_placeholder_escape($db->_real_escape(1.5)));

$escapeCases = json_decode(file_get_contents(__DIR__ . '/fixtures/escape-cases.json'), true);
$escapeBad = [];
foreach ($escapeCases as $case) {
	if ($db->remove_placeholder_escape($db->_real_escape($case['raw'])) !== $case['escaped']) {
		$escapeBad[] = substr($case['escaped'], 0, 60);
	}
}
same(
	'every literal captured from WordPress on MySQL re-escapes to the same bytes (' .
		count($escapeCases) .
		')',
	[],
	$escapeBad,
);

same(
	'prepare() output passes through unchanged to the host',
	"SELECT * FROM wp_options WHERE option_name = 'it\\'s' AND option_id = 5 AND f = 1.500000",
	$db->prepare(
		'SELECT * FROM wp_options WHERE option_name = %s AND option_id = %d AND f = %f',
		"it's",
		5,
		1.5,
	),
);
host(fixed(rows(['x'], [])));
$db->get_results(
	$db->prepare(
		'SELECT * FROM t WHERE a = %s AND b LIKE %s',
		"a\"b\\c",
		$db->esc_like('50%_') . '%',
	),
);
same(
	'the statement reaches the host exactly as prepare() built it',
	['SELECT * FROM t WHERE a = \'a\\"b\\\\c\' AND b LIKE \'50\\\\%\\\\_%\''],
	hostCalls(),
);
#endregion

#region session statements
section('session statements');
host(static fn(string $sql) => none());
$db = new BridgeWpdb('u', 'p', 'n', 'h');
same(
	'connect sends one statement; SET NAMES and the sql_mode read are answered locally',
	["SET SESSION sql_mode='NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"],
	hostCalls(),
);
host(static fn(string $sql) => none());
add_filter(
	'incompatible_sql_modes',
	static fn(array $modes) => array_merge($modes, ['NO_ENGINE_SUBSTITUTION']),
);
$db = new BridgeWpdb('u', 'p', 'n', 'h');
same(
	'the incompatible_sql_modes filter reaches the statement',
	["SET SESSION sql_mode='NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'"],
	hostCalls(),
);
remove_all_filters('incompatible_sql_modes');
host(static fn(string $sql) => none());
$db->set_sql_mode(['ONLY_FULL_GROUP_BY', 'ANSI_QUOTES']);
same(
	'set_sql_mode with a list sends that list less the incompatible modes',
	["SET SESSION sql_mode='ANSI_QUOTES'"],
	hostCalls(),
);
host(static fn(string $sql) => failure(1193, 'unknown variable'));
$db = new BridgeWpdb('u', 'p', 'n', 'h');
same('a failing sql_mode statement does not stop the connect', true, $db->ready);
#endregion

#region writes
section('writes');
$db = connect(fixed(write(1, 7)));
same(
	'insert returns rows affected',
	1,
	$db->insert('wp_options', ['option_name' => 'a', 'option_value' => 'b']),
);
same('insert_id is an int', 7, $db->insert_id);
same('rows_affected is an int', 1, $db->rows_affected);
same(
	'insert statement text',
	["INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES ('a', 'b')"],
	hostCalls(),
);

$db = connect(fixed(write(2)));
same(
	'update returns rows affected',
	2,
	$db->update('wp_options', ['option_value' => 'x'], ['option_name' => 'a']),
);
same('rows_affected after update', 2, $db->rows_affected);
same(
	'update statement text',
	["UPDATE `wp_options` SET `option_value` = 'x' WHERE `option_name` = 'a'"],
	hostCalls(),
);
same('delete returns rows affected', 2, $db->delete('wp_options', ['option_name' => 'a']));
same('replace returns rows affected', 2, $db->replace('wp_options', ['option_name' => 'a']));

$db = connect(fixed(write(1, 12)));
$db->query('INSERT INTO t VALUES (1)');
same('query() insert sets insert_id', 12, $db->insert_id);
$db->query('UPDATE t SET a = 1');
same('an update leaves the earlier insert_id alone', 12, $db->insert_id);

$db = connect(fixed(none()));
same('a write answered with kind none counts zero rows', 0, $db->query('INSERT INTO t VALUES (1)'));
same('and leaves insert_id 0', 0, $db->insert_id);

$db = connect(fixed(none()));
same('CREATE returns true', true, $db->query('CREATE TABLE t (a int)'));
same('ALTER returns true', true, $db->query('ALTER TABLE t ADD b int'));
same('TRUNCATE returns true', true, $db->query('TRUNCATE TABLE t'));
same('DROP returns true', true, $db->query('DROP TABLE t'));
same('SET returns 0 rows', 0, $db->query('SET @a = 1'));

$db = connect(fixed(failure(1062, "Duplicate entry 'a' for key 'PRIMARY'", '23000')));
$db->insert_id = 5;
$db->suppress_errors(true);
same('a failing insert returns false', false, $db->query('INSERT INTO t VALUES (1)'));
same('and clears insert_id', 0, $db->insert_id);
same('insert() returns false on failure', false, $db->insert('t', ['a' => 1]));
#endregion

#region reads
section('reads');
$db = connect(fixed(rows(['COUNT(*)'], [[5]])));
same('get_var on a COUNT is a string', '5', $db->get_var('SELECT COUNT(*) FROM wp_posts'));
same('query() on a read returns the row count', 1, $db->query('SELECT COUNT(*) FROM wp_posts'));
same('num_rows', 1, $db->num_rows);

$posts = rows(
	['ID', 'post_title', 'menu_order', 'ratio', 'note'],
	[[1, 'One', 0, 0.5, null], [2, 'Two', 3, 1.0, '']],
);
$db = connect(fixed($posts));

$row = $db->get_row('SELECT * FROM wp_posts', OBJECT);
same('get_row ->ID is the string "1"', '1', $row->ID);
same('get_row int zero is "0"', '0', $row->menu_order);
same('get_row float is MySQL text', '0.5', $row->ratio);
same('get_row null stays null', null, $row->note);
same('get_row at offset 1', '2', $db->get_row('SELECT * FROM wp_posts', OBJECT, 1)->ID);
same('get_row beyond the result is null', null, $db->get_row('SELECT * FROM wp_posts', OBJECT, 5));
same(
	'get_row ARRAY_A',
	['ID' => '1', 'post_title' => 'One', 'menu_order' => '0', 'ratio' => '0.5', 'note' => null],
	$db->get_row('SELECT * FROM wp_posts', ARRAY_A),
);
same(
	'get_row ARRAY_N',
	['1', 'One', '0', '0.5', null],
	$db->get_row('SELECT * FROM wp_posts', ARRAY_N),
);

$objects = $db->get_results('SELECT * FROM wp_posts', OBJECT);
same('get_results OBJECT is a list of two', 2, count($objects));
same('get_results OBJECT ->ID', ['1', '2'], [$objects[0]->ID, $objects[1]->ID]);
same(
	'get_results OBJECT ->menu_order',
	['0', '3'],
	[$objects[0]->menu_order, $objects[1]->menu_order],
);
same('a whole float 1.0 prints as 1', '1', $objects[1]->ratio);
same('an empty string stays an empty string', '', $objects[1]->note);
same(
	'get_results ARRAY_A',
	[
		['ID' => '1', 'post_title' => 'One', 'menu_order' => '0', 'ratio' => '0.5', 'note' => null],
		['ID' => '2', 'post_title' => 'Two', 'menu_order' => '3', 'ratio' => '1', 'note' => ''],
	],
	$db->get_results('SELECT * FROM wp_posts', ARRAY_A),
);
same(
	'get_results ARRAY_N',
	[['1', 'One', '0', '0.5', null], ['2', 'Two', '3', '1', '']],
	$db->get_results('SELECT * FROM wp_posts', ARRAY_N),
);
$keyed = $db->get_results('SELECT * FROM wp_posts', OBJECT_K);
same('get_results OBJECT_K keys by the first column', [1, 2], array_keys($keyed));
same('OBJECT_K rows hold string values', ['1', '2'], [$keyed[1]->ID, $keyed[2]->ID]);
same('get_results with no query is null', null, $db->get_results(''));
same(
	'get_results with an unknown mode is null',
	null,
	$db->get_results('SELECT * FROM wp_posts', 'BOGUS'),
);
same('get_var picks the column and row', 'Two', $db->get_var('SELECT * FROM wp_posts', 1, 1));
same('get_var of an empty string is null', null, $db->get_var('SELECT * FROM wp_posts', 4, 1));
same('get_col', ['1', '2'], $db->get_col('SELECT * FROM wp_posts', 0));
same('get_col second column', ['One', 'Two'], $db->get_col('SELECT * FROM wp_posts', 1));

$db = connect(fixed(rows(['x'], [])));
same('an empty result is an empty list', [], $db->get_results('SELECT x FROM t'));
same('and get_var is null', null, $db->get_var('SELECT x FROM t'));
same('and the query returns 0', 0, $db->query('SELECT x FROM t'));

$db = connect(fixed(rows(['term_id', 'name', 'term_id'], [[1, 'Uncategorized', 9]])));
$term = $db->get_row('SELECT t.*, tt.* FROM wp_terms t JOIN wp_term_taxonomy tt');
same('a repeated column name keeps the later value, as mysqli does', '9', $term->term_id);
same('and the object has one property per name', 2, count(get_object_vars($term)));

$db = connect(fixed(rows(['0', ''], [[1, 2]])));
$row = $db->get_row('SELECT 1, 2', ARRAY_A);
same('numeric and empty column names survive', ['0' => '1', '' => '2'], $row);

$db = connect(fixed(rows(['v'], [[true], [false]])));
same('booleans from a host become 1 and 0', ['1', '0'], $db->get_col('SELECT v'));

$floats = json_decode(file_get_contents(__DIR__ . '/fixtures/float-cases.json'), true);
$floatBad = [];
foreach ($floats as $case) {
	$text = BridgeWpdb::text((float) $case['value']);
	if ($text !== $case['mysql']) {
		$floatBad[] = $case['value'] . ' gave ' . $text . ' want ' . $case['mysql'];
	}
}
same(
	'floats print the way MySQL prints a DOUBLE column (' . count($floats) . ' cases from MySQL)',
	[],
	$floatBad,
);
same('an int is decimal text', '-12', BridgeWpdb::text(-12));
same('a string is unchanged', "x\0y", BridgeWpdb::text("x\0y"));
same('null is null', null, BridgeWpdb::text(null));
#endregion

#region errors
section('errors');
global $EZSQL_ERROR;
$db = connect(fixed(failure(1146, "Table 'wordpress.wp_nope' doesn't exist", '42S02')));
$db->hide_errors();
$db->suppress_errors(true);
$out = quiet(static fn() => $db->get_results('SELECT * FROM wp_nope'));
same('an error query returns false', false, $db->query('SELECT * FROM wp_nope'));
same('last_error is the MySQL text', "Table 'wordpress.wp_nope' doesn't exist", $db->last_error);
same('last_query is kept', 'SELECT * FROM wp_nope', $db->last_query);
same('get_results answers an empty list on error', [], $db->get_results('SELECT * FROM wp_nope'));
same('get_var answers null on error', null, $db->get_var('SELECT * FROM wp_nope'));
same('suppressed errors print nothing', '', $out);
ok(
	'EZSQL_ERROR records the error',
	end($EZSQL_ERROR)['error_str'] === "Table 'wordpress.wp_nope' doesn't exist",
);

$db->suppress_errors(false);
$db->show_errors(true);
$out = quiet(static fn() => $db->query('SELECT * FROM wp_nope'));
ok(
	'show_errors prints the WordPress error block',
	str_contains(
		$out,
		'<div id="error"><p class="wpdberror"><strong>WordPress database error:</strong> [Table',
	) && str_contains($out, '<code>SELECT * FROM wp_nope</code>'),
	$out,
);

$db->show_errors(false);
$out = quiet(static fn() => $db->query('SELECT * FROM wp_nope'));
same('hide_errors keeps the page clean', '', $out);
same('show_errors() returns the previous state', false, $db->show_errors(true));
same('suppress_errors() returns the previous state', false, $db->suppress_errors(true));

$db = connect(fixed(failure(1235, 'FOR UPDATE is not supported', 'HY000', 'FOR UPDATE')));
$db->suppress_errors(true);
same('a refusal returns false', false, $db->query('SELECT 1 FOR UPDATE'));
same('a refusal reads as a database error', 'FOR UPDATE is not supported', $db->last_error);
$db->flush();
same('flush clears last_error', '', $db->last_error);

$db = connect(fixed(['ok' => false, 'refused' => 'LOCK TABLES']));
$db->suppress_errors(true);
$db->query('LOCK TABLES t WRITE');
same(
	'a refusal with no text still sets last_error',
	'Statement refused: LOCK TABLES',
	$db->last_error,
);

$calls = 0;
$db = connect(static function (string $sql) use (&$calls): array {
	if (str_starts_with($sql, 'SET SESSION')) {
		return none();
	}
	$calls++;
	return $calls === 1 ? failure(2006, 'MySQL server has gone away') : rows(['x'], [[1]]);
});
same('a gone-away error retries the statement once', 1, $db->query('SELECT 1'));
same('and the host saw it twice', 2, $calls);

$calls = 0;
$db = connect(static function (string $sql) use (&$calls): array {
	if (str_starts_with($sql, 'SET SESSION')) {
		return none();
	}
	$calls++;
	return $calls === 1 ? failure(2006, 'MySQL server has gone away') : rows(['x'], [[1]]);
});
$db->query('SELECT 1');
same(
	'a gone-away error reconnects and resends the session mode before the retry',
	[
		'SELECT 1',
		"SET SESSION sql_mode='NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
		'SELECT 1',
	],
	hostCalls(),
);
same('and the link error is clear after the retry', 0, (fn() => $this->linkErrno)->call($db));

$calls = 0;
$db = connect(static function (string $sql) use (&$calls): array {
	if (str_starts_with($sql, 'SET SESSION')) {
		return none();
	}
	$calls++;
	return failure(2006, 'MySQL server has gone away');
});
$db->suppress_errors(true);
same('a link that stays gone is retried once and then fails', false, $db->query('SELECT 1'));
same('and the host saw the statement twice', 2, $calls);

$calls = 0;
$db = connect(static function (string $sql) use (&$calls): array {
	if (str_starts_with($sql, 'SET SESSION')) {
		return none();
	}
	$calls++;
	return failure(1064, 'You have an error in your SQL syntax', '42000');
});
$db->suppress_errors(true);
$db->query('SELEKT');
same('an ordinary error is not retried', 1, $calls);

$db = connect(fixed(none()));
$db->suppress_errors(true);
same('an empty query is false', false, $db->query(''));
$db->ready = false;
same('a database that is not ready answers false and sends nothing', false, $db->query('SELECT 1'));
same('and the host saw nothing', [], hostCalls());
#endregion

#region server info
section('server info');
host(
	static fn(string $sql) => str_starts_with($sql, 'SET SESSION')
		? none()
		: rows(['VERSION()'], [['8.0.40-fake']]),
);
$db = new BridgeWpdb('u', 'p', 'n', 'h');
$GLOBALS['hostCalls'] = [];
same('db_server_info is what the host answers', '8.0.40-fake', $db->db_server_info());
same('db_version strips the suffix', '8.0.40', $db->db_version());
$db->db_server_info();
$db->db_version();
same('and it asks once per object', ['SELECT VERSION()'], hostCalls());

host(
	static fn(string $sql) => str_starts_with($sql, 'SET SESSION')
		? none()
		: failure(1064, 'no', 'HY000', 'VERSION'),
);
$db = new BridgeWpdb('u', 'p', 'n', 'h');
$GLOBALS['hostCalls'] = [];
same(
	'a refused VERSION() falls back to a version string',
	'8.0.17-drupflare',
	$db->db_server_info(),
);
same('with a usable db_version', '8.0.17', $db->db_version());
$db->db_server_info();
same('and the refusal is not asked twice', 1, count(hostCalls()));
ok(
	'the fallback clears the 5.5.5 minimum',
	version_compare($db->db_version(), $GLOBALS['required_mysql_version'] ?? '5.5.5', '>='),
);
$GLOBALS['hostCalls'] = [];

host(
	static fn(string $sql) => str_starts_with($sql, 'SET SESSION')
		? none()
		: rows(['VERSION()'], [[8]]),
);
$db = new BridgeWpdb('u', 'p', 'n', 'h');
same('a non-string VERSION() falls back', '8.0.17-drupflare', $db->db_server_info());
#endregion

#region capabilities
section('capabilities');
$db = connect(fixed(none()));
foreach (
	[
		'collation',
		'group_concat',
		'subqueries',
		'set_charset',
		'utf8mb4',
		'utf8mb4_520',
		'identifier_placeholders',
	]
	as $cap
) {
	same("has_cap($cap)", true, $db->has_cap($cap));
}
same('has_cap is case-insensitive', true, $db->has_cap('UTF8MB4_520'));
same('has_cap of an unknown name is false', false, $db->has_cap('window_functions'));
same('has_cap sends nothing to the host', [], hostCalls());
#endregion

#region column info and flush
section('column info');
$db = connect(fixed(rows(['ID', 'post_title'], [[1, 'One']])));
same('get_col_info is null before any read', null, $db->get_col_info());
$db->get_results('SELECT ID, post_title FROM wp_posts');
same('get_col_info names', ['ID', 'post_title'], $db->get_col_info('name'));
same('get_col_info at an offset', 'post_title', $db->get_col_info('name', 1));
same('orgname is the name', ['ID', 'post_title'], $db->get_col_info('orgname'));
same('what the bridge does not carry is null', null, $db->get_col_info('table', 0));
same('col_info is readable as a property', 'ID', $db->col_info[0]->name);
$db->flush();
same('flush clears col_info', null, $db->col_info);
same('flush clears last_result', [], $db->last_result);
same('flush clears num_rows', 0, $db->num_rows);
same('flush clears rows_affected', 0, $db->rows_affected);
same('flush clears last_query', null, $db->last_query);
$db->get_results('SELECT ID, post_title FROM wp_posts');
$db->query('UPDATE t SET a = 1');
same('a write after a read leaves no column info', null, $db->col_info);
#endregion

#region charset paths
section('charset paths');
$db = connect(fixed(none()));
same('get_col_charset answers utf8mb4', 'utf8mb4', $db->get_col_charset('wp_posts', 'post_title'));
same('so does a table that never existed', 'utf8mb4', $db->get_col_charset('wp_nope', 'x'));
same(
	'get_col_length is false with no column metadata',
	false,
	$db->get_col_length('wp_posts', 'post_title'),
);
same('none of that reached the host', [], hostCalls());

add_filter('pre_get_col_charset', static fn($charset) => 'latin1', 5);
same(
	'an earlier filter keeps its answer',
	'latin1',
	$db->get_col_charset('wp_posts', 'post_title'),
);
remove_all_filters('pre_get_col_charset');
$db = connect(fixed(none()));

$db = connect(fixed(write(1, 3)));
same(
	'invalid UTF-8 in a value makes insert() refuse, as it does on MySQL',
	false,
	$db->insert('wp_posts', ['post_title' => "bad \xc3\x28 byte", 'post_content' => 'ok']),
);
same('and nothing, SHOW FULL COLUMNS included, reaches the host', [], hostCalls());
$db = connect(fixed(write(1, 3)));
$db->insert('wp_posts', ['post_title' => "h\u{e9}llo \u{65e5}\u{672c}\u{8a9e} \u{1f600}"]);
same(
	'multi-byte text goes through whole',
	[
		"INSERT INTO `wp_posts` (`post_title`) VALUES ('h\u{e9}llo \u{65e5}\u{672c}\u{8a9e} \u{1f600}')",
	],
	hostCalls(),
);
$db = connect(fixed(rows(['x'], [])));
$db->suppress_errors(true);
same(
	'a read with invalid UTF-8 in its text is refused by core and never sent',
	false,
	$db->query("SELECT x FROM wp_posts WHERE x = 'bad \xc3\x28'"),
);
same(
	'with the core message in last_error',
	true,
	str_contains($db->last_error, 'contains invalid data'),
);
same('and nothing at the host', [], hostCalls());
#endregion

#region large and binary data
section('large and binary data');
$big = str_repeat("0123456789abcdef\u{e9}\n'", 55000);
$seen = null;
$db = connect(static function (string $sql) use (&$seen, $big): array {
	if (str_starts_with($sql, 'SET SESSION')) {
		return none();
	}
	$seen = $sql;
	return str_starts_with($sql, 'INSERT') ? write(1, 1) : rows(['v'], [[$big]]);
});
ok('the 1 MB string is over 1 MB', strlen($big) > 1000000);
$db->insert('wp_options', ['option_value' => $big]);
ok(
	'the whole 1 MB value reaches the host escaped',
	is_string($seen) && str_contains($seen, str_replace(["\n", "'"], ['\\n', "\\'"], $big)),
	'statement was ' . strlen((string) $seen) . ' bytes',
);
same('a 1 MB value comes back intact from get_var', $big, $db->get_var('SELECT v FROM t'));

$blob = "a\0b\x1a\r\n'\"\\\u{1f600}";
$db = connect(static function (string $sql) use (&$seen, $blob): array {
	if (str_starts_with($sql, 'SET SESSION')) {
		return none();
	}
	$seen = $sql;
	return rows(['v'], [[$blob]]);
});
$db->get_var($db->prepare('SELECT %s', $blob));
same(
	'NUL, ctrl-Z, CR, LF, quotes and backslash are escaped',
	"SELECT 'a\\0b\\Z\\r\\n\\'\\\"\\\\\u{1f600}'",
	$seen,
);
same('the same bytes come back from the host', $blob, $db->get_var('SELECT v FROM t'));
#endregion

#region caller
section('caller');
$db = connect(fixed(rows(['x'], [[1]])));
function suiteCaller(BridgeWpdb $db): void
{
	$db->get_var('SELECT 1');
}
suiteCaller($db);
$last = end($db->queries);
ok('SAVEQUERIES logs the statement', $last[0] === 'SELECT 1');
ok(
	'and a caller with no wpdb frames in it',
	str_ends_with($last[2], 'suiteCaller') && !str_contains($last[2], 'wpdb->'),
	$last[2],
);
#endregion

#region connection lifecycle
section('connection lifecycle');
$db = connect(fixed(rows(['x'], [[1]])));
same('close returns true', true, $db->close());
same('close twice returns false', false, $db->close());
same('a closed database is not ready', false, $db->ready);
same('and a query on it is false', false, $db->query('SELECT 1'));
same('db_connect reconnects', true, $db->db_connect());
same('and the session statement goes out again', 1, count(hostCalls()));
same('after that the query runs', 1, $db->query('SELECT 1'));
same('check_connection is true while the bridge is installed', true, $db->check_connection());

$GLOBALS['hostInstalled'] = false;
host(fixed(none()));
$GLOBALS['hostInstalled'] = false;
$db = new BridgeWpdb('u', 'p', 'n', 'h');
same('with no host function the database is not ready', false, $db->ready);
same('dbh is empty', true, empty($db->dbh));
ok(
	'the error is a WP_Error with the connect code',
	$db->error instanceof WP_Error && $db->error->get_error_code() === 'db_connect_fail',
);
ok(
	'and its message names the missing function',
	str_contains($db->error->get_error_message(), 'cfwMysqlExec'),
);
same('a query is false', false, $db->query('SELECT 1'));
same('db_connect(false) is false without bailing', false, $db->db_connect(false));
same('check_connection(false) is false', false, $db->check_connection(false));

$db = new BridgeWpdb('u', 'p', 'n', 'h');
$db->show_errors(true);
$thrown = '';
try {
	$db->db_connect();
} catch (RuntimeException $e) {
	$thrown = $e->getMessage();
}
ok(
	'with show_errors the page dies through wp_die',
	str_starts_with($thrown, 'wp_die: <h1>Error establishing a database connection'),
	$thrown,
);
$GLOBALS['hostInstalled'] = true;
#endregion

#region replay
section('replay: front page, cold');
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/front-page-cold.json'), true);
$steps = $fixture['steps'];
$position = 0;
$mismatch = [];
host(static function (string $sql) use ($steps, &$position, &$mismatch): array {
	if (str_starts_with($sql, 'SET SESSION')) {
		return none();
	}
	$step = $steps[$position] ?? null;
	if ($step === null || $step['sql'] !== $sql) {
		$mismatch[] = ['at' => $position, 'got' => $sql];
		return failure(1105, 'out of script');
	}
	$position++;
	return $step['reply'];
});
$db = new BridgeWpdb('u', 'p', 'n', 'h');
same(
	'connect sends only the sql_mode of the capture\'s four session statements',
	[$fixture['session'][3]],
	hostCalls(),
);
$results = [];
foreach ($steps as $i => $step) {
	$output = $step['output'] ?? OBJECT;
	$results[$i] = match ($step['call']) {
		'get_results' => $db->get_results($step['sql'], $output),
		'get_row' => $db->get_row($step['sql'], OBJECT),
		'get_col' => $db->get_col($step['sql']),
		'get_var' => $db->get_var($step['sql']),
	};
}
same('every replayed statement matched the script in order', [], $mismatch);
same('all ' . count($steps) . ' statements ran', count($steps), $position);
same(
	'host calls: the sql_mode plus the replay, against 28 on MySQL',
	1 + count($steps),
	count(hostCalls()),
);
same('num_queries counts the replayed statements', count($steps), $db->num_queries);
same('WP_Query ids come back as strings', ['1'], $results[4]);
same('FOUND_ROWS() is a string', '1', $results[5]);
same('the post row has a string ID', '1', $results[6][0]->ID);
same('and a string post_author', '1', $results[6][0]->post_author);
same(
	'with the escaped content intact',
	"Welcome to WordPress.\n\nIt's \"quoted\" \\ backslash.",
	$results[6][0]->post_content,
);
same(
	'post meta in ARRAY_A',
	[['post_id' => '1', 'meta_key' => '_edit_lock', 'meta_value' => '1794889771:1']],
	$results[7],
);
same('term rows keep the later term_id', '1', $results[9][0]->term_id);
same('an empty option read is null', null, $results[1]);
same('an option read returns its row', '1', $results[13]->option_value);
same('pages keyed by ID in OBJECT_K', [2], array_keys($results[18]));
same(
	'usermeta in ARRAY_N',
	[['1', 'nickname', 'admin'], ['1', 'rich_editing', 'true']],
	$results[20],
);
same(
	'the user row has a string ID and status',
	['1', '0'],
	[$results[21][0]->ID, $results[21][0]->user_status],
);
#endregion

#region mysqli surface
section('mysqli surface');
$source = file_get_contents($wpRoot . '/wp-includes/class-wpdb.php');
$lines = explode("\n", $source);
$parent = new ReflectionClass(wpdb::class);
$touching = [];
foreach ($parent->getMethods() as $method) {
	if ($method->getDeclaringClass()->getName() !== 'wpdb') {
		continue;
	}
	$body = implode(
		"\n",
		array_slice(
			$lines,
			$method->getStartLine() - 1,
			$method->getEndLine() - $method->getStartLine() + 1,
		),
	);
	if (preg_match('/mysqli/i', $body) === 1) {
		$touching[] = $method->getName();
	}
}
sort($touching);
$unreached = ['_do_query', 'flush', 'strip_invalid_text'];
$replaced = [];
$missed = [];
foreach ($touching as $name) {
	$declared = (new ReflectionMethod(BridgeWpdb::class, $name))->getDeclaringClass()->getName();
	if ($declared === BridgeWpdb::class) {
		$replaced[] = $name;
	} elseif (!in_array($name, $unreached, true)) {
		$missed[] = $name;
	}
}
echo '  info parent methods that name mysqli: ' . implode(', ', $touching) . "\n";
echo '  info replaced here: ' . implode(', ', $replaced) . "\n";
same('every parent method that names mysqli is replaced or on the unreachable list', [], $missed);
$own =
	file_get_contents(__DIR__ . '/../../src/Wp/db.php') .
	file_get_contents(__DIR__ . '/../../src/Wp/Bridge.php');
same(
	'neither new file calls a mysqli function or class',
	0,
	preg_match('/mysqli_\w+\s*\(|instanceof\s+mysqli|new\s+mysqli/i', $own),
);
$db = connect(fixed(none()));
same(
	'strip_invalid_text can reach mysqli_character_set_name only with an empty charset, and charset is set',
	'utf8mb4',
	$db->charset,
);
same(
	'determine_charset never leaves it empty',
	'utf8mb4',
	$db->determine_charset('', '')['charset'],
);
#endregion

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
