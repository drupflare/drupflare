<?php

/**
 * @file
 * Drives the tag emitter in src/Wp/mu/drupflare-host/tags.php: the tags a page carries, the tags a
 * write announces, and how the emitter reads a statement and its caller.
 *
 * Real WordPress: plugin.php, class-wp-hook.php, class-wp-query.php, class-wp-post.php and
 * class-wp-object-cache.php. A small fake tree stands in for the rest, laid out like WordPress's so
 * the emitter sees real call frames: a database class and a few core functions under wp-includes,
 * and a naive plugin under wp-content.
 *
 * Needs PHP and a WordPress checkout. The tree comes from WORDPRESS_SRC (default
 * ../../../worker/wordpress-src), the host helpers from WORKER_SRC (default ../../../worker) and
 * the plugin directory from DRUPFLARE_MU_DIR (default ../../src/Wp/mu).
 *
 * Usage:
 *   php tests/wp/tags-suite.php
 */

$wpRoot = rtrim(getenv('WORDPRESS_SRC') ?: __DIR__ . '/../../../worker/wordpress-src', '/');
$workerRoot = rtrim(getenv('WORKER_SRC') ?: __DIR__ . '/../../../worker', '/');
$muDir = rtrim(getenv('DRUPFLARE_MU_DIR') ?: __DIR__ . '/../../src/Wp/mu', '/');
$helpers = $workerRoot . '/src/site/php/host-helpers.php';

foreach (
	[
		$wpRoot . '/wp-includes/plugin.php',
		$wpRoot . '/wp-includes/class-wp-hook.php',
		$wpRoot . '/wp-includes/class-wp-query.php',
		$wpRoot . '/wp-includes/class-wp-post.php',
		$wpRoot . '/wp-includes/class-wp-object-cache.php',
		$helpers,
		$muDir . '/drupflare-host.php',
		$muDir . '/drupflare-host/tags.php',
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

#region fake tree
$tree = sys_get_temp_dir() . '/tags-suite-' . getmypid();
foreach (['wp-includes', 'wp-content/plugins/naive', 'wp-content/mu-plugins'] as $dir) {
	mkdir("$tree/$dir", 0777, true);
}
foreach (['plugin.php', 'class-wp-hook.php', 'class-wp-filter-sentinel.php'] as $core) {
	copy($wpRoot . '/wp-includes/' . $core, "$tree/wp-includes/$core");
}

file_put_contents(
	"$tree/wp-includes/class-wpdb.php",
	<<<'PHP'
	<?php
	class wpdb
	{
		public $prefix = 'wp_';
		public $term_taxonomy = 'wp_term_taxonomy';
		public $log = [];
		public $var = null;

		public function query($sql)
		{
			$sql = apply_filters('query', $sql);
			$this->log[] = $sql;
			return 0;
		}

		public function get_results($sql)
		{
			$this->query($sql);
			return [];
		}

		public function get_var($sql)
		{
			$this->query($sql);
			return is_array($this->var) ? array_shift($this->var) : $this->var;
		}

		public function prepare($query, ...$args)
		{
			return vsprintf($query, $args);
		}
	}
	PHP
	,
);
file_put_contents(
	"$tree/wp-includes/link-template.php",
	<<<'PHP'
	<?php
	function get_adjacent_post()
	{
		global $wpdb;
		return $wpdb->get_var('SELECT p.ID FROM wp_posts AS p WHERE p.post_date < NOW()');
	}

	function some_unknown_core_reader()
	{
		global $wpdb;
		return $wpdb->get_results('SELECT * FROM wp_postmeta WHERE meta_key = 1');
	}

	function core_fake_write()
	{
		global $wpdb;
		return $wpdb->query("UPDATE wp_posts SET post_title = 'x' WHERE ID = 1");
	}

	function get_option()
	{
		global $wpdb;
		return $wpdb->get_results("SELECT option_value FROM wp_options WHERE option_name = 'x'");
	}
	PHP
	,
);
file_put_contents(
	"$tree/wp-content/plugins/naive/naive.php",
	<<<'PHP'
	<?php
	function naive_plugin_read()
	{
		global $wpdb;
		return $wpdb->get_results("SELECT * FROM wp_posts WHERE post_type = 'event'");
	}

	function naive_plugin_custom_table()
	{
		global $wpdb;
		return $wpdb->get_results('SELECT * FROM wp_myplugin_votes');
	}

	function naive_plugin_write()
	{
		global $wpdb;
		return $wpdb->query("UPDATE wp_posts SET post_title = 'x' WHERE ID = 1");
	}

	function naive_plugin_own_table_write()
	{
		global $wpdb;
		return $wpdb->query("INSERT INTO wp_myplugin_votes (a) VALUES ('1')");
	}

	function naive_plugin_ddl()
	{
		global $wpdb;
		return $wpdb->query('CREATE TABLE wp_myplugin_votes (a int)');
	}
	PHP
	,
);

define('ABSPATH', "$tree/");
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', "$tree/wp-content");

function is_multisite()
{
	return false;
}

function wp_suspend_cache_addition()
{
	return false;
}

function get_post_types($args = [])
{
	return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment'];
}

function get_post($id)
{
	return $GLOBALS['fakePosts'][(int) $id] ?? null;
}

function get_comment($id)
{
	return $GLOBALS['fakeComments'][(int) $id] ?? null;
}

$GLOBALS['fakePosts'] = [];
$GLOBALS['fakeComments'] = [];

require "$tree/wp-includes/plugin.php";
require "$tree/wp-includes/class-wpdb.php";
require "$tree/wp-includes/link-template.php";
require "$tree/wp-content/plugins/naive/naive.php";
require $wpRoot . '/wp-includes/class-wp-post.php';
require $wpRoot . '/wp-includes/class-wp-query.php';
require $wpRoot . '/wp-includes/class-wp-object-cache.php';
#endregion

#region fake host
$GLOBALS['hostMode'] = 'ok';
$GLOBALS['calls'] = [];

function vrzno_env(string $name): mixed
{
	if ($name !== 'cfwWpTags' || $GLOBALS['hostMode'] === 'absent') {
		return null;
	}
	return static function (string $json): string {
		$GLOBALS['calls'][] = json_decode($json, true);
		return json_encode(
			$GLOBALS['hostMode'] === 'refuse' ? ['ok' => false, 'error' => 'no'] : ['ok' => true],
		);
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
require $muDir . '/drupflare-host/tags.php';
#endregion

$wpdb = new wpdb();
$wp_object_cache = new WP_Object_Cache();
drupflare_tags_register();

function fresh(string $host = 'ok'): void
{
	global $wpdb, $wp_object_cache;
	drupflare_tags_reset();
	$GLOBALS['calls'] = [];
	$GLOBALS['hostMode'] = $host;
	$GLOBALS['fakePosts'] = [];
	$GLOBALS['fakeComments'] = [];
	$wpdb->log = [];
	$wpdb->var = null;
	$wp_object_cache = new WP_Object_Cache();
}

/** @return list<string> */
function sentTags(): array
{
	$tags = array_keys(drupflare_tags_state()['sent']);
	sort($tags);
	return $tags;
}

/** @return list<string> */
function wholesaleReasons(): array
{
	return array_keys(drupflare_tags_state()['wholesale']);
}

/** @return list<string> */
function quietTables(): array
{
	return array_keys(drupflare_tags_state()['quiet']);
}

function post(int $id, string $type = 'post', string $status = 'publish'): WP_Post
{
	$post = new WP_Post((object) ['ID' => $id, 'post_type' => $type, 'post_status' => $status]);
	$GLOBALS['fakePosts'][$id] = $post;
	return $post;
}

function comment(int $id, int $postId, string $approved): object
{
	$comment = (object) [
		'comment_ID' => $id,
		'comment_post_ID' => $postId,
		'comment_approved' => $approved,
	];
	$GLOBALS['fakeComments'][$id] = $comment;
	return $comment;
}

function query(array $vars, array $posts, string $flag = ''): WP_Query
{
	$query = new WP_Query();
	$query->query_vars = $vars;
	$query->posts = $posts;
	if ($flag !== '') {
		$query->$flag = true;
	}
	return $query;
}

#region the loader
section('the loader');
$loaderText = (string) file_get_contents($muDir . '/drupflare-host.php');
ok(
	'the loader requires the emitter and registers it at include, not at plugins_loaded',
	str_contains($loaderText, "require_once __DIR__ . '/drupflare-host/tags.php';") &&
		str_contains($loaderText, "\ndrupflare_tags_register();"),
);
$probe = <<<'PHP'
define('ABSPATH', '/x/');
$hooks = [];
function add_action($h) { $GLOBALS['hooks'][] = $h; }
function add_filter($h) { $GLOBALS['hooks'][] = $h; }
function remove_action() {}
require getenv('MU_FILE');
echo json_encode($hooks);
PHP;
$run = proc_open(
	[PHP_BINARY, '-r', $probe],
	[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
	$pipes,
	null,
	['MU_FILE' => $muDir . '/drupflare-host.php'],
);
$registered = json_decode((string) stream_get_contents($pipes[1]), true) ?: [];
proc_close($run);
foreach (
	['clean_post_cache', 'added_option', 'posts_pre_query', 'pre_option', 'query', 'switch_theme']
	as $hook
) {
	ok("including the loader registers $hook", in_array($hook, $registered, true));
}
ok(
	'every hook handler exists and takes the arguments it is given',
	(static function (): bool {
		foreach (drupflare_tags_hooks() as [$hook, $handler, $args]) {
			if (!is_callable($handler)) {
				return false;
			}
			if ((new ReflectionFunction($handler))->getNumberOfParameters() < $args) {
				return false;
			}
		}
		return true;
	})(),
);
#endregion

#region grammar
section('names and tags');
same('a plain name is itself', 'blogname', drupflare_tags_name('blogname'));
same(
	'a name of 64 characters is itself',
	str_repeat('a', 64),
	drupflare_tags_name(str_repeat('a', 64)),
);
ok(
	'a long name becomes a short hash that is still a valid tag segment',
	drupflare_tags_valid('option:' . drupflare_tags_name(str_repeat('a', 65))),
);
ok('a name with a colon becomes a hash', str_starts_with(drupflare_tags_name('a:b'), 'x'));
same(
	'the same odd name hashes the same way twice',
	drupflare_tags_name('a b'),
	drupflare_tags_name('a b'),
);
ok('a tag with a space is not valid', !drupflare_tags_valid('post:1 2'));
ok('a tag in capitals is not valid', !drupflare_tags_valid('Post:1'));
#endregion

#region hook rows
section('what each hook announces');

/**
 * One row per hook in drupflare_tags_hooks(): the arguments WordPress passes, and what the emitter
 * must say. `tags` is the announced list, `wholesale` a reason, `quiet` a table, `none` nothing.
 *
 * @return array<string, list<array{hook: string, args: callable, tags?: list<string>, wholesale?: string, quiet?: string, none?: bool, label: string}>>
 */
function hookRows(): array
{
	$publish = static fn(int $id = 5, string $type = 'post') => post($id, $type);
	$sets = [
		[
			'clean_post_cache',
			static fn() => [5, $publish()],
			['post:5', 'posts', 'posts:post'],
			'a published post',
		],
		[
			'clean_post_cache',
			static fn() => [5, post(5, 'post', 'draft')],
			['post:5'],
			'a draft names only itself',
		],
		[
			'clean_post_cache',
			static fn() => [5, post(5, 'revision', 'inherit')],
			['post:5'],
			'a revision names only itself',
		],
		[
			'clean_post_cache',
			static fn() => [5, post(5, 'page')],
			['post:5', 'posts', 'posts:page'],
			'a published page',
		],
		[
			'transition_post_status',
			static fn() => ['publish', 'draft', $publish()],
			['post:5', 'posts', 'posts:post'],
			'publishing',
		],
		[
			'transition_post_status',
			static fn() => ['trash', 'publish', $publish()],
			['post:5', 'posts', 'posts:post'],
			'trashing a published post',
		],
		[
			'transition_post_status',
			static fn() => ['draft', 'auto-draft', $publish()],
			[],
			'a draft saving says nothing here',
		],
		['added_post_meta', static fn() => [1, 9, 'k', 'v'], ['post:9'], 'a post meta added'],
		['updated_post_meta', static fn() => [1, 9, 'k', 'v'], ['post:9'], 'a post meta updated'],
		['deleted_post_meta', static fn() => [[1], 9, 'k', 'v'], ['post:9'], 'a post meta deleted'],
		[
			'added_term_relationship',
			static fn() => [5, 3, 'category'],
			['post:5', 'posts', 'posts:post'],
			'a published post gains a term',
			static fn() => post(5),
		],
		[
			'deleted_term_relationships',
			static fn() => [5, [3], 'category'],
			['post:5', 'posts', 'posts:post'],
			'a published post loses a term',
			static fn() => post(5),
		],
		[
			'added_term_relationship',
			static fn() => [5, 3, 'category'],
			['post:5'],
			'a draft gains a term',
			static fn() => post(5, 'post', 'draft'),
		],
		[
			'added_term_relationship',
			static fn() => [5, 3, 'nav_menu'],
			null,
			'a menu item gains a menu',
		],
		[
			'created_term',
			static fn() => [7, 8, 'category'],
			['term:7', 'terms', 'terms:category'],
			'a term created',
		],
		[
			'edited_term',
			static fn() => [7, 8, 'category'],
			['term:7', 'terms', 'terms:category'],
			'a term edited',
		],
		[
			'delete_term',
			static fn() => [7, 8, 'category'],
			['term:7', 'terms', 'terms:category'],
			'a term deleted',
		],
		['edited_term', static fn() => [7, 8, 'nav_menu'], null, 'a menu term edited'],
		[
			'edited_term_taxonomy',
			static fn() => [8, 'category'],
			['term:7', 'terms', 'terms:category'],
			'a term count changed',
			static function () {
				$GLOBALS['wpdb']->var = ['1', '2', '7'];
				do_action('edit_term_taxonomy', 8, 'category');
			},
		],
		[
			'edited_term_taxonomy',
			static fn() => [8, 'category'],
			false,
			'a term count rewritten with the same value is quiet',
			static function () {
				$GLOBALS['wpdb']->var = ['2', '2'];
				do_action('edit_term_taxonomy', 8, 'category');
			},
			'edit_term_taxonomy',
		],
		[
			'edited_term_taxonomy',
			static fn() => [8, 'category'],
			['term:7', 'terms', 'terms:category'],
			'a term count with no earlier reading is announced',
			static function () {
				$GLOBALS['wpdb']->var = ['7'];
			},
		],
		['added_term_meta', static fn() => [1, 7, 'k', 'v'], ['term:7'], 'a term meta added'],
		['updated_term_meta', static fn() => [1, 7, 'k', 'v'], ['term:7'], 'a term meta updated'],
		['deleted_term_meta', static fn() => [[1], 7, 'k', 'v'], ['term:7'], 'a term meta deleted'],
		[
			'wp_insert_comment',
			static fn() => [3, comment(3, 12, '1')],
			['comments', 'comments:12', 'post:12'],
			'an approved comment',
		],
		[
			'wp_insert_comment',
			static fn() => [3, comment(3, 12, '0')],
			false,
			'a held comment is quiet',
		],
		[
			'wp_insert_comment',
			static fn() => [3, comment(3, 12, 'spam')],
			false,
			'a spam comment is quiet',
		],
		[
			'transition_comment_status',
			static fn() => ['approved', 'unapproved', comment(3, 12, '1')],
			['comments', 'comments:12', 'post:12'],
			'a comment approved',
		],
		[
			'transition_comment_status',
			static fn() => ['unapproved', 'approved', comment(3, 12, '0')],
			['comments', 'comments:12', 'post:12'],
			'a comment unapproved',
		],
		[
			'transition_comment_status',
			static fn() => ['spam', 'unapproved', comment(3, 12, 'spam')],
			false,
			'a held comment marked spam is quiet',
		],
		[
			'deleted_comment',
			static fn() => [3, comment(3, 12, '1')],
			['comments', 'comments:12', 'post:12'],
			'an approved comment deleted',
		],
		[
			'edit_comment',
			static fn() => [3],
			['comments', 'comments:12', 'post:12'],
			'an approved comment edited',
			static fn() => comment(3, 12, '1'),
		],
		[
			'trashed_post_comments',
			static fn() => [12, ['3' => '1']],
			['comments', 'comments:12', 'post:12'],
			'a post trashed with its comments',
		],
		[
			'untrashed_post_comments',
			static fn() => [12],
			['comments', 'comments:12', 'post:12'],
			'a post restored with its comments',
		],
		[
			'added_comment_meta',
			static fn() => [1, 3, 'k', 'v'],
			['comments', 'comments:12', 'post:12'],
			'a comment meta added',
			static fn() => comment(3, 12, '1'),
		],
		[
			'updated_comment_meta',
			static fn() => [1, 3, 'k', 'v'],
			['comments', 'comments:12', 'post:12'],
			'a comment meta updated',
			static fn() => comment(3, 12, '1'),
		],
		[
			'deleted_comment_meta',
			static fn() => [[1], 3, 'k', 'v'],
			false,
			'a held comment meta is quiet',
			static fn() => comment(3, 12, '0'),
		],
		['clean_user_cache', static fn() => [4, new stdClass()], ['user:4'], 'a user changed'],
		['user_register', static fn() => [4, []], ['user:4', 'users'], 'a user created'],
		[
			'deleted_user',
			static fn() => [4, null, new stdClass()],
			['user:4', 'users'],
			'a user deleted',
		],
		[
			'added_user_meta',
			static fn() => [1, 4, 'description', 'x'],
			['user:4'],
			'a public user meta added',
		],
		[
			'updated_user_meta',
			static fn() => [1, 4, 'nickname', 'x'],
			['user:4'],
			'a public user meta updated',
		],
		[
			'deleted_user_meta',
			static fn() => [[1], 4, 'twitter', 'x'],
			['user:4'],
			'a custom user meta deleted',
		],
		[
			'updated_user_meta',
			static fn() => [1, 4, 'session_tokens', 'x'],
			false,
			'a session token is quiet',
		],
		[
			'added_option',
			static fn() => ['my_plugin_setting', 'x'],
			['option:my_plugin_setting'],
			'an option added',
		],
		[
			'updated_option',
			static fn() => ['my_plugin_setting', 'a', 'b'],
			['option:my_plugin_setting'],
			'an option updated',
		],
		[
			'deleted_option',
			static fn() => ['my_plugin_setting'],
			['option:my_plugin_setting'],
			'an option deleted',
		],
		[
			'updated_option',
			static fn() => ['blogname', 'a', 'b'],
			'option:blogname',
			'a structural option is wholesale',
		],
		[
			'updated_option',
			static fn() => ['widget_meta', [], []],
			'option:widget_meta',
			'a widget option is wholesale',
		],
		['updated_option', static fn() => ['cron', [], []], false, 'the cron option is quiet'],
		['switch_theme', static fn() => ['x'], 'structural', 'a theme switch'],
		['wp_create_nav_menu', static fn() => [1], 'structural', 'a menu created'],
		['wp_update_nav_menu', static fn() => [1], 'structural', 'a menu updated'],
		['wp_delete_nav_menu', static fn() => [1], 'structural', 'a menu deleted'],
		['wp_update_nav_menu_item', static fn() => [1, 2], 'structural', 'a menu item updated'],
		[
			'customize_save_after',
			static fn() => [new stdClass()],
			'structural',
			'the customizer saved',
		],
		[
			'clean_post_cache',
			static fn() => [5, post(5, 'nav_menu_item')],
			'structural-post',
			'a menu item post',
		],
		[
			'clean_post_cache',
			static fn() => [5, post(5, 'wp_template')],
			'structural-post',
			'a template post',
		],
		[
			'clean_post_cache',
			static fn() => [5, null],
			'post-unknown',
			'a post that cannot be read',
		],
	];
	$rows = [];
	foreach ($sets as $set) {
		[$hook, $args, $expect, $label] = $set;
		$row = [
			'hook' => $hook,
			'args' => $args,
			'label' => $label,
			'prepare' => $set[4] ?? null,
			'also' => $set[5] ?? null,
		];
		if (is_array($expect)) {
			$row['tags'] = $expect;
		} elseif ($expect === false) {
			$row['quiet'] = true;
		} elseif ($expect === null) {
			$row['wholesale'] = 'menu';
		} else {
			$row['wholesale'] = $expect;
		}
		$rows[] = $row;
	}
	return $rows;
}

/** runs one row and says whether the emitter said exactly what the row expects */
function rowHolds(array $row): bool
{
	fresh();
	if ($row['prepare'] !== null) {
		$made = $row['prepare']();
	}
	$args = $row['args']();
	do_action($row['hook'], ...$args);
	$tags = sentTags();
	$wholesale = wholesaleReasons();
	$quiet = quietTables();
	if (isset($row['tags'])) {
		$expected = $row['tags'];
		sort($expected);
		return $tags === $expected && $wholesale === [];
	}
	if (isset($row['wholesale'])) {
		return $tags === [] && count($wholesale) === 1 && $wholesale[0] === $row['wholesale'];
	}
	return $tags === [] && $wholesale === [] && $quiet !== [];
}

// a row whose label says nothing is announced still counts quiet or nothing, never a tag
$rows = hookRows();
$byHook = [];
foreach ($rows as $row) {
	$byHook[$row['hook']] = true;
	if ($row['also'] !== null) {
		$byHook[$row['also']] = true;
	}
}
$registeredHooks = array_unique(array_column(drupflare_tags_hooks(), 0));
sort($registeredHooks);
$testedHooks = array_keys($byHook);
sort($testedHooks);
same('every registered hook has at least one scenario row', $registeredHooks, $testedHooks);

foreach ($rows as $row) {
	// the control row: "says nothing" rows need the draft case, which announces nothing at all
	if ($row['label'] === 'a draft saving says nothing here') {
		fresh();
		do_action($row['hook'], ...$row['args']());
		same($row['hook'] . ': ' . $row['label'], [], sentTags());
		continue;
	}
	ok($row['hook'] . ': ' . $row['label'], rowHolds($row));
}

section('a mutation control for every hook: remove the handler and the row stops holding');
foreach (drupflare_tags_hooks() as [$hook, $handler, $args]) {
	foreach ($rows as $row) {
		if ($row['hook'] !== $hook && $row['also'] !== $hook) {
			continue;
		}
		if ($row['label'] === 'a draft saving says nothing here') {
			continue;
		}
		remove_action($hook, $handler, 10);
		$holds = rowHolds($row);
		add_action($hook, $handler, 10, $args);
		ok("control: without $handler on $hook, '{$row['label']}' fails", !$holds);
	}
}
#endregion

#region host calls
section('the calls the host receives');
fresh();
do_action('clean_post_cache', 5, post(5));
same('an announcement is one announce call', 'announce', $GLOBALS['calls'][0]['op'] ?? null);
same(
	'it carries the tags once, in order made',
	['post:5', 'posts:post', 'posts'],
	$GLOBALS['calls'][0]['tags'] ?? null,
);
do_action('clean_post_cache', 5, post(5));
same('the same tags are not sent twice in one request', 1, count($GLOBALS['calls']));
do_action('clean_post_cache', 6, post(6));
same('a new post sends only what is new', ['post:6'], $GLOBALS['calls'][1]['tags'] ?? null);

fresh();
do_action('switch_theme', 'x');
do_action('wp_update_nav_menu', 1);
same('a wholesale reason is sent once per reason', 1, count($GLOBALS['calls']));
same(
	'and it names the reason',
	['op' => 'wholesale', 'reason' => 'structural'],
	$GLOBALS['calls'][0] ?? null,
);

fresh();
do_action('updated_option', 'cron', 1, 2);
do_action('updated_option', 'cron', 2, 3);
same('a quiet table is sent once', [['op' => 'quiet', 'table' => 'options']], $GLOBALS['calls']);

fresh('refuse');
do_action('clean_post_cache', 5, post(5));
$ops = array_column($GLOBALS['calls'], 'op');
same('a refused announcement falls back to a wholesale purge', ['announce', 'wholesale'], $ops);
same('and names why', 'host-refused', $GLOBALS['calls'][1]['reason'] ?? null);

fresh('absent');
do_action('clean_post_cache', 5, post(5));
same('with no host function nothing is sent', [], $GLOBALS['calls']);
same('but the request still knows it announced', ['post:5', 'posts', 'posts:post'], sentTags());

fresh();
$many = [];
for ($i = 0; $i < 300; $i++) {
	$many[] = 'post:' . $i;
}
drupflare_tags_announce($many);
same(
	'more than 256 tags in one request is a wholesale purge',
	['too-many-tags'],
	wholesaleReasons(),
);
drupflare_tags_announce(['Bad Tag']);
ok(
	'a tag outside the grammar is a wholesale purge',
	in_array('invalid-tag', wholesaleReasons(), true),
);
#endregion

#region reads
section('option reads');
fresh();
apply_filters('pre_option', false, 'my_plugin_setting', false);
apply_filters('pre_option', false, 'blogname', false);
apply_filters('pre_option', false, 'cron', false);
apply_filters('pre_option', false, 'theme_mods_x', false);
apply_filters('pre_option', false, '_transient_wp_core_block_css_files', false);
apply_filters('pre_option', false, 'a name with spaces', false);
$state = drupflare_tags_state();
$noted = array_keys($state['tags']);
sort($noted);
same(
	'a plain option is tagged; a structural, a quiet and a theme option are not',
	[
		'option:_transient_wp_core_block_css_files',
		'option:my_plugin_setting',
		'option:' . drupflare_tags_name('a name with spaces'),
	],
	$noted,
);
same(
	'the pre_option filter hands the value on untouched',
	'x',
	apply_filters('pre_option', 'x', 'my_plugin_setting', false),
);
remove_filter('pre_option', 'drupflare_tags_on_pre_option', 0);
fresh();
apply_filters('pre_option', false, 'my_plugin_setting', false);
same(
	'control: without the filter no option read is tagged',
	[],
	array_keys(drupflare_tags_state()['tags']),
);
add_filter('pre_option', 'drupflare_tags_on_pre_option', 0, 2);

section('queries');
$types = static function (array $vars, array $posts, string $flag = '', array $emit = []): array {
	fresh();
	$query = query($vars, $posts, $flag);
	apply_filters('posts_pre_query', null, $query);
	$tags = drupflare_tags_collect();
	return $tags['tags'];
};
$listing = $types(['post_type' => 'post'], [post(5), post(6)]);
same(
	'a listing carries each post and the listing of its type',
	['post:5', 'post:6', 'posts:post'],
	$listing,
);
$single = $types(['post_type' => ''], [post(5)], 'is_singular');
same('a single post carries only itself', ['post:5'], $single);
$notFound = $types(['post_type' => ''], [], 'is_singular');
same(
	'a single post that was not found depends on the listing, so a new post reaches it',
	['posts:post'],
	$notFound,
);
same(
	'a page query with no type reads as pages',
	['post:7', 'posts:page'],
	$types(['post_type' => ''], [post(7, 'page')], 'is_page'),
);
same(
	'an attachment query reads as attachments',
	['posts:attachment'],
	$types(['post_type' => ''], [], 'is_attachment'),
);
same(
	'any lists every searchable type',
	['posts:attachment', 'posts:page', 'posts:post'],
	$types(['post_type' => 'any'], []),
);
same(
	'an array names each type',
	['posts:event', 'posts:post'],
	$types(['post_type' => ['post', 'event']], []),
);
same(
	'a query by ids carries the ids',
	['post:1', 'post:2', 'posts:post'],
	$types(['post_type' => 'post'], [1, 2]),
);

fresh();
apply_filters('posts_pre_query', [post(5)], query(['post_type' => 'post'], []));
$answer = drupflare_tags_collect();
same(
	'a query another plugin answered first is untaggable',
	'posts-pre-query-override',
	$answer['untaggable'],
);
same('and a page that is untaggable carries no tags at all', [], $answer['tags']);

fresh();
apply_filters(
	'terms_pre_query',
	null,
	(object) ['query_vars' => ['taxonomy' => ['category', 'post_tag']]],
);
apply_filters('terms_pre_query', null, (object) ['query_vars' => ['taxonomy' => []]]);
same(
	'a term query tags each taxonomy, and all of them when none is named',
	['terms', 'terms:category', 'terms:post_tag'],
	drupflare_tags_collect()['tags'],
);
fresh();
apply_filters('comments_pre_query', null, (object) ['query_vars' => ['post_id' => 12]]);
apply_filters('comments_pre_query', null, (object) ['query_vars' => []]);
same(
	'a comment query tags its post, or all comments',
	['comments', 'comments:12'],
	drupflare_tags_collect()['tags'],
);
fresh();
apply_filters('users_pre_query', null, new stdClass());
same('a user query tags the user listing', ['users'], drupflare_tags_collect()['tags']);
fresh();
apply_filters('terms_pre_query', ['x'], (object) ['query_vars' => []]);
same(
	'a term query another plugin answered is untaggable',
	'terms-pre-query-override',
	drupflare_tags_collect()['untaggable'],
);
fresh();
apply_filters('comments_pre_query', ['x'], (object) ['query_vars' => []]);
same(
	'a comment query another plugin answered is untaggable',
	'comments-pre-query-override',
	drupflare_tags_collect()['untaggable'],
);
fresh();
apply_filters('users_pre_query', ['x'], new stdClass());
same(
	'a user query another plugin answered is untaggable',
	'users-pre-query-override',
	drupflare_tags_collect()['untaggable'],
);
#endregion

#region cache
section('what the object cache holds');

/**
 * Fills the cache with one entry per rule and says which tag each must produce.
 *
 * @return array<string, array{group: string, key: int|string, value: mixed, tag: string}>
 */
function cacheRows(): array
{
	return [
		'a post' => ['group' => 'posts', 'key' => 5, 'value' => new stdClass(), 'tag' => 'post:5'],
		'a post meta list' => [
			'group' => 'post_meta',
			'key' => 5,
			'value' => [],
			'tag' => 'post:5',
		],
		'a term' => ['group' => 'terms', 'key' => 3, 'value' => new stdClass(), 'tag' => 'term:3'],
		'a term meta list' => [
			'group' => 'term_meta',
			'key' => 3,
			'value' => [],
			'tag' => 'term:3',
		],
		'a user' => ['group' => 'users', 'key' => 4, 'value' => new stdClass(), 'tag' => 'user:4'],
		'a user meta list' => [
			'group' => 'user_meta',
			'key' => 4,
			'value' => [],
			'tag' => 'user:4',
		],
		'a comment' => [
			'group' => 'comment',
			'key' => 9,
			'value' => (object) ['comment_post_ID' => 12],
			'tag' => 'comments:12',
		],
		'a post to term relationship' => [
			'group' => 'category_relationships',
			'key' => 5,
			'value' => [3],
			'tag' => 'post:5',
		],
		'a custom taxonomy relationship' => [
			'group' => 'genre_relationships',
			'key' => 8,
			'value' => [3],
			'tag' => 'post:8',
		],
		'a post count' => [
			'group' => 'counts',
			'key' => 'posts-post',
			'value' => new stdClass(),
			'tag' => 'posts:post',
		],
		'a readable post count' => [
			'group' => 'counts',
			'key' => 'posts-page_readable',
			'value' => new stdClass(),
			'tag' => 'posts:page',
		],
		'a comment count for a post' => [
			'group' => 'counts',
			'key' => 'comments-12',
			'value' => new stdClass(),
			'tag' => 'comments:12',
		],
		'a site comment count' => [
			'group' => 'counts',
			'key' => 'comments-0',
			'value' => new stdClass(),
			'tag' => 'comments',
		],
		'an attachment count' => [
			'group' => 'counts',
			'key' => 'attachments',
			'value' => [],
			'tag' => 'posts:attachment',
		],
		'the last post time' => [
			'group' => 'timeinfo',
			'key' => 'lastpostmodified:server',
			'value' => 'x',
			'tag' => 'posts',
		],
	];
}

foreach (cacheRows() as $name => $row) {
	fresh();
	global $wp_object_cache;
	$wp_object_cache->set($row['key'], $row['value'], $row['group']);
	$got = drupflare_tags_collect();
	ok("$name carries {$row['tag']}", in_array($row['tag'], $got['tags'], true), json_encode($got));
	fresh();
	$none = drupflare_tags_collect();
	ok("control: without $name the tag is absent", !in_array($row['tag'], $none['tags'], true));
}

fresh();
$wp_object_cache->set(1, [], 'link_category_relationships');
$wp_object_cache->set('last_changed', 'x', 'posts');
$wp_object_cache->set('alloptions', [], 'options');
$wp_object_cache->set('abc', [], 'post-queries');
$wp_object_cache->set(5, 'x', 'a_plugin_group');
$wp_object_cache->set(6, new stdClass(), 'posts');
same(
	'a link category, a salt, the options, a query result and a plugin group carry no tag',
	['post:6'],
	drupflare_tags_collect()['tags'],
);
fresh();
$wp_object_cache->set('mystery', 1, 'counts');
$weird = drupflare_tags_collect();
ok(
	'a count the emitter does not know makes the page untaggable',
	str_starts_with((string) $weird['untaggable'], 'count:'),
);

fresh();
$wp_object_cache = new class {
	public $cache = ['posts' => [5 => 1]];
};
same(
	'a cache that is not the stock one makes the page untaggable',
	'object-cache-opaque',
	drupflare_tags_collect()['untaggable'],
);
$wp_object_cache = new WP_Object_Cache();
#endregion

#region statements
section('how a statement is read');
$classify = static fn(string $sql) => drupflare_tags_classify($sql);
same(
	'a read of a content table',
	['verb' => 'SELECT', 'content' => ['wp_posts'], 'foreign' => []],
	$classify('SELECT * FROM wp_posts WHERE ID = 1'),
);
same(
	'a join names both',
	['wp_posts', 'wp_postmeta'],
	$classify('SELECT * FROM wp_posts p INNER JOIN wp_postmeta m ON p.ID = m.post_id')['content'],
);
same(
	'a plugin table is foreign',
	['wp_myplugin_votes'],
	$classify('SELECT * FROM wp_myplugin_votes')['foreign'],
);
same(
	'a table named in a string is not read',
	[],
	$classify("SELECT 1 FROM DUAL WHERE x = 'FROM wp_posts'")['content'],
);
same('DUAL is not a table', [], $classify('SELECT 1 FROM DUAL')['foreign']);
same('a comment does not name a table', [], $classify('SELECT /* FROM wp_posts */ 1')['content']);
same(
	'a write names the table it changes',
	['INSERT', ['wp_posts']],
	[
		$classify("INSERT INTO wp_posts (a) VALUES ('1')")['verb'],
		$classify("INSERT INTO wp_posts (a) VALUES ('1')")['content'],
	],
);
same(
	'a subselect read is a read of its table',
	['wp_posts'],
	$classify('SELECT FOUND_ROWS() FROM (SELECT ID FROM wp_posts) AS t')['content'],
);
same(
	'a statement with no table names none',
	['verb' => 'SELECT', 'content' => [], 'foreign' => []],
	$classify('SELECT FOUND_ROWS()'),
);
same(
	'a prefix other than wp_ is read',
	['wp9_posts'],
	drupflare_tags_classify('SELECT * FROM wp9_posts', 'wp9_')['content'],
);

section('who called');
$call = static function (callable $fn): array {
	fresh();
	global $wpdb;
	$wpdb->log = [];
	$fn();
	$state = drupflare_tags_state();
	return [
		'reasons' => $state['reasons'],
		'wholesale' => array_keys($state['wholesale']),
		'readers' => array_keys($state['readers']),
		'tags' => array_keys($state['tags']),
	];
};
$core = $call(static fn() => get_adjacent_post());
same('a core reader we account for is fine and adds its listing', ['posts'], $core['tags']);
same('and is recorded by name', ['get_adjacent_post'], $core['readers']);
same('with no reason against the page', [], $core['reasons']);
$unknown = $call(static fn() => some_unknown_core_reader());
same(
	'a core reader we do not know makes the page untaggable',
	['unrecognised-reader:some_unknown_core_reader'],
	$unknown['reasons'],
);
$naive = $call(static fn() => naive_plugin_read());
same(
	'a plugin reading a content table directly makes the page untaggable',
	['direct-read:naive.php'],
	$naive['reasons'],
);
$custom = $call(static fn() => naive_plugin_custom_table());
same(
	'a plugin table read makes the page untaggable',
	['custom-table:wp_myplugin_votes'],
	$custom['reasons'],
);
$optionRead = $call(static fn() => get_option());
same('a core reader of options is not a reason', [], $optionRead['reasons']);

$wholesaleOf = static fn(callable $fn): array => $call($fn)['wholesale'];
same(
	'a plugin writing a content table directly is a wholesale purge',
	['direct-write:naive.php'],
	$wholesaleOf(static fn() => naive_plugin_write()),
);
same('a core write is left to its hooks', [], $wholesaleOf(static fn() => core_fake_write()));
same(
	'a plugin writing its own table is left alone',
	[],
	$wholesaleOf(static fn() => naive_plugin_own_table_write()),
);
same(
	'a plugin creating its own table is a wholesale purge, since schema changes are',
	['schema'],
	$wholesaleOf(static fn() => naive_plugin_ddl()),
);
same(
	'a plugin creating a table is a wholesale purge',
	['schema'],
	$wholesaleOf(static fn() => $GLOBALS['wpdb']->query('ALTER TABLE wp_posts ADD x int')),
);

fresh();
$GLOBALS['wpdb']->var = '7';
drupflare_tags_quietly(static fn() => naive_plugin_read());
same(
	'a read the emitter makes for itself is not the page\'s',
	[],
	drupflare_tags_state()['reasons'],
);

remove_filter('query', 'drupflare_tags_on_query', 99999);
$off = $call(static fn() => naive_plugin_read());
same('control: without the query filter the direct read goes unseen', [], $off['reasons']);
add_filter('query', 'drupflare_tags_on_query', 99999);
#endregion

#region the request as a whole
section('the page the request ends with');
fresh();
$wp_object_cache->set(5, new stdClass(), 'posts');
$done = drupflare_tags_finish();
same('a page with tags is taggable', null, $done['untaggable']);
same('and names them', ['post:5'], $done['tags']);
same('and the run ends with a settle call', ['op' => 'settle'], end($GLOBALS['calls']));

fresh();
$done = drupflare_tags_finish();
same('a page with no tags is untaggable', 'no-tags', $done['untaggable']);

fresh();
$wp_object_cache->set(5, new stdClass(), 'posts');
do_action('updated_option', 'my_plugin_setting', 'a', 'b');
$done = drupflare_tags_finish();
same('a request that announced something is untaggable', 'request-wrote', $done['untaggable']);

fresh();
$wp_object_cache->set(5, new stdClass(), 'posts');
do_action('updated_option', 'cron', 'a', 'b');
$done = drupflare_tags_finish();
same('a quiet write does not make the page untaggable', null, $done['untaggable']);

fresh('refuse');
$wp_object_cache->set(5, new stdClass(), 'posts');
$done = drupflare_tags_finish();
same('a settle the host refused is untaggable', 'host-call-failed', $done['untaggable']);
fresh('absent');
$wp_object_cache->set(5, new stdClass(), 'posts');
$done = drupflare_tags_finish();
same('with no host the page is still named', ['post:5'], $done['tags']);

fresh();
$wp_object_cache->set(5, new stdClass(), 'posts');
naive_plugin_read();
$done = drupflare_tags_finish();
same(
	'the naive plugin leaves the page untaggable and with no tags',
	[[], 'direct-read:naive.php'],
	[$done['tags'], $done['untaggable']],
);
#endregion

foreach (glob("$tree/*/*") ?: [] as $entry) {
	if (is_dir($entry)) {
		foreach (glob("$entry/*") ?: [] as $leaf) {
			is_dir($leaf) ? array_map('unlink', glob("$leaf/*") ?: []) : unlink($leaf);
			@rmdir($leaf);
		}
		@rmdir($entry);
	} else {
		unlink($entry);
	}
}
@rmdir("$tree/wp-content/plugins");
@rmdir("$tree/wp-content");
@rmdir("$tree/wp-includes");
@rmdir($tree);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
