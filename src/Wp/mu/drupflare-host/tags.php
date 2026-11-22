<?php

/**
 * @return list<string>
 */
function drupflare_tags_core_tables(): array
{
	return [
		'posts',
		'postmeta',
		'terms',
		'term_taxonomy',
		'term_relationships',
		'termmeta',
		'comments',
		'commentmeta',
		'users',
		'usermeta',
		'options',
		'links',
	];
}

/**
 * Post types whose changes alter the theme or the menus on every page.
 *
 * @return list<string>
 */
function drupflare_tags_structural_types(): array
{
	return [
		'nav_menu_item',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
	];
}

/**
 * Post types no listing shows, so a change to one never purges the all-posts tag.
 *
 * @return list<string>
 */
function drupflare_tags_hidden_types(): array
{
	return ['revision', 'oembed_cache', 'customize_changeset', 'user_request'];
}

/**
 * Options the request machinery rewrites on its own schedule and no page shows. Every page reads
 * `cron`, and a cron run rewrites it, so tagging it would purge the site on every tick.
 */
function drupflare_tags_quiet_option(string $name): bool
{
	return in_array(
		$name,
		['cron', 'doing_cron', '_transient_doing_cron', '_transient_timeout_doing_cron'],
		true,
	);
}

/**
 * Options whose change can alter any page, and so purge wholesale. Everything else is tagged
 * `option:<name>` on the pages that read it.
 */
function drupflare_tags_structural_option(string $name): bool
{
	if (preg_match('/^(?:theme_mods_|widget_|sidebars_widgets$|nav_menu_options$)/', $name) === 1) {
		return true;
	}
	return in_array(
		$name,
		[
			'siteurl',
			'home',
			'blogname',
			'blogdescription',
			'blog_charset',
			'blog_public',
			'template',
			'stylesheet',
			'current_theme',
			'active_plugins',
			'active_sitewide_plugins',
			'permalink_structure',
			'category_base',
			'tag_base',
			'rewrite_rules',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'posts_per_page',
			'posts_per_rss',
			'rss_use_excerpt',
			'date_format',
			'time_format',
			'timezone_string',
			'gmt_offset',
			'start_of_week',
			'WPLANG',
			'sticky_posts',
			'site_icon',
			'custom_logo',
			'default_comment_status',
			'comment_registration',
			'comments_per_page',
			'default_comments_page',
			'comment_order',
			'page_comments',
			'thread_comments',
			'thread_comments_depth',
			'show_avatars',
			'avatar_default',
			'avatar_rating',
			'users_can_register',
			'default_role',
			'use_smilies',
			'wp_user_roles',
			'wp_page_for_privacy_policy',
			'uploads_use_yearmonth_folders',
			'upload_path',
			'upload_url_path',
			'wp_force_deactivated_plugins',
		],
		true,
	);
}

/**
 * User meta keys that hold a person's screen settings, not anything a page shows.
 */
function drupflare_tags_quiet_user_meta(string $key): bool
{
	return preg_match(
		'/(?:^|_)(?:session_tokens|user-settings(?:-time)?|dashboard_quick_press_last_post_id|per_page|persisted_preferences)$|^(?:community-events-location|dismissed_wp_pointers|show_welcome_panel|closedpostboxes_|metaboxhidden_|meta-box-order_|screen_layout_|managenav|wp_media_library_mode|wp_user-settings)/',
		$key,
	) === 1;
}

/**
 * The readers of a content table whose dependencies the emitter accounts for, each with the
 * listing tags that reader adds. A function not named here marks the page untaggable.
 *
 * @return array<string, list<string>>
 */
function drupflare_tags_readers(): array
{
	return [
		// per-object reads: the object cache holds what they loaded, and the cache supplies the tag
		'WP_Post::get_instance' => [],
		'WP_Comment::get_instance' => [],
		'WP_User::get_data_by' => ['users'],
		'WP_Term::get_instance' => [],
		'get_term' => [],
		'update_meta_cache' => [],
		'_prime_post_caches' => [],
		'update_post_caches' => [],
		'update_term_cache' => [],
		'update_object_term_cache' => [],
		'wp_get_object_terms' => ['terms'],
		'update_comment_cache' => [],
		'_prime_comment_caches' => [],
		'update_user_caches' => [],
		'cache_users' => [],
		'_prime_term_caches' => [],
		'wp_load_alloptions' => [],
		'wp_prime_option_caches' => [],
		'get_option' => [],
		'add_option' => [],
		'update_option' => [],
		'delete_option' => [],
		'get_transient' => [],
		// queries: the pre-query hooks recorded each one
		'WP_Query::get_posts' => [],
		'WP_Term_Query::get_terms' => [],
		'WP_Comment_Query::get_comment_ids' => [],
		'WP_User_Query::query' => [],
		'WP_User_Query::get_search_sql' => [],
		// readers with no per-object cache, so each adds the listing it depends on
		'get_adjacent_post' => ['posts'],
		'get_boundary_post' => ['posts'],
		'wp_get_archives' => ['posts'],
		'get_calendar' => ['posts'],
		'_get_last_post_time' => ['posts'],
		'get_lastpostdate' => ['posts'],
		'get_lastpostmodified' => ['posts'],
		'wp_count_posts' => ['posts'],
		'wp_count_attachments' => ['posts'],
		'wp_count_comments' => ['comments'],
		'get_comment_count' => ['comments'],
		'get_lastcommentmodified' => ['comments'],
		'wp_list_authors' => ['posts', 'users'],
		'get_pages' => ['posts:page'],
		// a page lookup that finds its page is covered by the page's own tag; one that misses waits on a new page
		'get_page_by_path' => ['posts:page'],
		'get_page_by_title' => ['posts'],
		'url_to_postid' => ['posts'],
		'post_exists' => ['posts'],
		'get_term_by' => ['terms'],
		'wp_count_terms' => ['terms'],
		'get_objects_in_term' => ['posts', 'terms'],
		'is_blog_installed' => [],
	];
}

/**
 * @return array<string, mixed>
 */
function &drupflare_tags_state(): array
{
	static $state = [];
	if ($state === []) {
		$state = drupflare_tags_blank();
	}
	return $state;
}

/**
 * @return array<string, mixed>
 */
function drupflare_tags_blank(): array
{
	return [
		'tags' => [],
		'queries' => [],
		'termQueries' => [],
		'commentQueries' => [],
		'userQueries' => [],
		'reasons' => [],
		'readers' => [],
		'sent' => [],
		'wholesale' => [],
		'quiet' => [],
		'counts' => [],
		'failed' => false,
		'busy' => 0,
	];
}

function drupflare_tags_reset(): void
{
	$state = &drupflare_tags_state();
	$state = drupflare_tags_blank();
}

function drupflare_tags_valid(string $tag): bool
{
	return preg_match('/^[a-z_]+(?::[A-Za-z0-9_.-]{1,64})?$/', $tag) === 1;
}

/**
 * A name as a tag segment: itself when it is plain, else a short hash, so two odd names can
 * share a tag and never miss one.
 */
function drupflare_tags_name(string $value): string
{
	if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $value) === 1) {
		return $value;
	}
	return 'x' . substr(md5($value), 0, 16);
}

function drupflare_tags_untaggable(string $reason): void
{
	$state = &drupflare_tags_state();
	if (count($state['reasons']) < 20 && !in_array($reason, $state['reasons'], true)) {
		$state['reasons'][] = $reason;
	}
}

function drupflare_tags_note(string $tag): void
{
	$state = &drupflare_tags_state();
	if (!drupflare_tags_valid($tag)) {
		drupflare_tags_untaggable('invalid-tag');
		return;
	}
	if (count($state['tags']) >= 512) {
		drupflare_tags_untaggable('too-many-tags');
		return;
	}
	$state['tags'][$tag] = true;
}

/**
 * Runs code whose own queries and reads are not the page's.
 */
function drupflare_tags_quietly(callable $work): mixed
{
	$state = &drupflare_tags_state();
	$state['busy']++;
	try {
		return $work();
	} finally {
		$state['busy']--;
	}
}

/**
 * @param array<string, mixed> $payload
 *
 * @return array<string, mixed>|null
 */
function drupflare_tags_call(array $payload): ?array
{
	if (!function_exists('cfw_host') || !function_exists('cfw_call')) {
		return null;
	}
	$fn = cfw_host('cfwWpTags');
	if ($fn === null) {
		return null;
	}
	try {
		$reply = cfw_call($fn, $payload);
	} catch (Throwable $e) {
		$reply = ['ok' => false, 'error' => $e->getMessage()];
	}
	if (($reply['ok'] ?? false) !== true) {
		$state = &drupflare_tags_state();
		$state['failed'] = true;
	}
	return $reply;
}

/**
 * Tells the host these tags changed. The write has already happened, so a refusal falls back to a
 * wholesale purge.
 *
 * @param list<string> $tags
 */
function drupflare_tags_announce(array $tags): void
{
	$state = &drupflare_tags_state();
	$fresh = [];
	foreach ($tags as $tag) {
		if (!drupflare_tags_valid($tag)) {
			drupflare_tags_wholesale('invalid-tag');
			return;
		}
		if (!isset($state['sent'][$tag])) {
			$fresh[$tag] = true;
		}
	}
	if ($fresh === []) {
		return;
	}
	if (count($state['sent']) + count($fresh) > 256) {
		drupflare_tags_wholesale('too-many-tags');
		return;
	}
	foreach (array_keys($fresh) as $tag) {
		$state['sent'][$tag] = true;
	}
	$reply = drupflare_tags_call(['op' => 'announce', 'tags' => array_keys($fresh)]);
	if ($reply !== null && ($reply['ok'] ?? false) !== true) {
		drupflare_tags_wholesale('host-refused');
	}
}

/**
 * Says a write to this table happened and no page shows it, so the host does not treat it as an
 * announcement nobody made.
 */
function drupflare_tags_quiet(string $table): void
{
	$state = &drupflare_tags_state();
	if (isset($state['quiet'][$table])) {
		return;
	}
	$state['quiet'][$table] = true;
	drupflare_tags_call(['op' => 'quiet', 'table' => $table]);
}

/**
 * A change nothing can scope: the whole site goes stale.
 */
function drupflare_tags_wholesale(string $reason): void
{
	$state = &drupflare_tags_state();
	if (isset($state['wholesale'][$reason]) || count($state['wholesale']) >= 8) {
		return;
	}
	$state['wholesale'][$reason] = true;
	drupflare_tags_call(['op' => 'wholesale', 'reason' => $reason]);
}

#region tags a page carries

/**
 * @return list<string>
 */
function drupflare_tags_listing_for_type(string $type): array
{
	return ['posts:' . drupflare_tags_name($type)];
}

/**
 * What a change to a published post of this type invalidates: its listing, and the all-posts tag
 * that readers with no per-type cache carry.
 *
 * @return list<string>
 */
function drupflare_tags_listing_for_write(string $type): array
{
	$tags = drupflare_tags_listing_for_type($type);
	if (!in_array($type, drupflare_tags_hidden_types(), true)) {
		$tags[] = 'posts';
	}
	return $tags;
}

/**
 * @param array<int|string, mixed>|string|null $type
 *
 * @return list<string>
 */
function drupflare_tags_query_types(WP_Query $query, mixed $type): array
{
	if (is_array($type)) {
		$types = array_values(array_filter(array_map('strval', $type)));
		return $types === [] ? ['post'] : $types;
	}
	$type = (string) $type;
	if ($type === 'any') {
		return array_values(get_post_types(['exclude_from_search' => false]));
	}
	if ($type !== '') {
		return [$type];
	}
	if ($query->is_attachment()) {
		return ['attachment'];
	}
	return [$query->is_page() ? 'page' : 'post'];
}

function drupflare_tags_on_posts_pre_query(mixed $posts, mixed $query): mixed
{
	$state = &drupflare_tags_state();
	if ($state['busy'] > 0 || !($query instanceof WP_Query)) {
		return $posts;
	}
	// something answered the query before the database did, so its source is not known
	if ($posts !== null) {
		drupflare_tags_untaggable('posts-pre-query-override');
	}
	$state['queries'][] = $query;
	return $posts;
}

function drupflare_tags_on_terms_pre_query(mixed $terms, mixed $query): mixed
{
	$state = &drupflare_tags_state();
	if ($state['busy'] > 0) {
		return $terms;
	}
	if ($terms !== null) {
		drupflare_tags_untaggable('terms-pre-query-override');
	}
	$state['termQueries'][] = $query;
	return $terms;
}

function drupflare_tags_on_comments_pre_query(mixed $comments, mixed $query): mixed
{
	$state = &drupflare_tags_state();
	if ($state['busy'] > 0) {
		return $comments;
	}
	if ($comments !== null) {
		drupflare_tags_untaggable('comments-pre-query-override');
	}
	$state['commentQueries'][] = $query;
	return $comments;
}

function drupflare_tags_on_users_pre_query(mixed $users, mixed $query): mixed
{
	$state = &drupflare_tags_state();
	if ($state['busy'] > 0) {
		return $users;
	}
	if ($users !== null) {
		drupflare_tags_untaggable('users-pre-query-override');
	}
	$state['userQueries'][] = $query;
	return $users;
}

function drupflare_tags_on_pre_option(mixed $pre, mixed $option): mixed
{
	$state = &drupflare_tags_state();
	if ($state['busy'] > 0 || !is_scalar($option)) {
		return $pre;
	}
	$name = trim((string) $option);
	if (
		$name !== '' &&
		!drupflare_tags_quiet_option($name) &&
		!drupflare_tags_structural_option($name)
	) {
		drupflare_tags_note('option:' . drupflare_tags_name($name));
	}
	return $pre;
}

/**
 * The files of the database class in use and its parents, whose frames are not a caller.
 *
 * @return list<string>
 */
function drupflare_tags_wpdb_files(): array
{
	global $wpdb;
	$files = [];
	if (is_object($wpdb)) {
		for (
			$class = new ReflectionClass($wpdb);
			$class !== false;
			$class = $class->getParentClass()
		) {
			$files[] = (string) $class->getFileName();
		}
	}
	return $files;
}

/**
 * @return array{string, string}
 */
function drupflare_tags_call_site(): array
{
	$skip = array_map(
		'drupflare_tags_real',
		array_merge(
			[
				ABSPATH . WPINC . '/class-wpdb.php',
				ABSPATH . WPINC . '/class-wp-hook.php',
				ABSPATH . WPINC . '/plugin.php',
				__FILE__,
			],
			drupflare_tags_wpdb_files(),
		),
	);
	$frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16);
	foreach ($frames as $i => $frame) {
		$file = (string) ($frame['file'] ?? '');
		if ($file === '' || in_array($file, $skip, true)) {
			continue;
		}
		$caller = $frames[$i + 1] ?? [];
		$name = (string) ($caller['function'] ?? '');
		if (isset($caller['class'])) {
			$name = $caller['class'] . '::' . $name;
		}
		return [$file, $name];
	}
	return ['', ''];
}

/**
 * A path as PHP reports it in a backtrace, which is the resolved one, so a symlinked checkout does
 * not make core look like a plugin.
 */
function drupflare_tags_real(string $path): string
{
	static $seen = [];
	return $seen[$path] ??= realpath($path) ?: $path;
}

function drupflare_tags_core_file(string $file): bool
{
	$root = drupflare_tags_real(ABSPATH) . '/';
	$content = defined('WP_CONTENT_DIR') ? drupflare_tags_real((string) WP_CONTENT_DIR) . '/' : '';
	if ($file === '' || strncmp($file, $root, strlen($root)) !== 0) {
		return false;
	}
	return $content === '' || strncmp($file, $content, strlen($content)) !== 0;
}

/**
 * What one statement is: its verb, the content tables it mentions and the tables it reads or
 * writes that core does not own.
 *
 * @return array{verb: string, content: list<string>, foreign: list<string>}
 */
function drupflare_tags_classify(string $sql, string $prefix = 'wp_'): array
{
	$text = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql) ?? $sql;
	$text = preg_replace('#/\*.*?\*/#s', ' ', $text) ?? $text;
	preg_match('/^[\s(]*([A-Za-z]+)/', $text, $verb);
	$verb = strtoupper($verb[1] ?? '');
	$core = array_map(
		static fn(string $name): string => $prefix . $name,
		drupflare_tags_core_tables(),
	);
	preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+`?([A-Za-z0-9_$]+)`?/i', $text, $found);
	$content = [];
	$foreign = [];
	foreach ($found[1] as $table) {
		if (in_array($table, $core, true)) {
			$content[$table] = true;
		} elseif (strtolower($table) !== 'dual') {
			$foreign[$table] = true;
		}
	}
	return [
		'verb' => $verb,
		'content' => array_keys($content),
		'foreign' => array_keys($foreign),
	];
}

function drupflare_tags_on_query(mixed $sql): mixed
{
	$state = &drupflare_tags_state();
	if ($state['busy'] > 0 || !is_string($sql)) {
		return $sql;
	}
	$class = drupflare_tags_classify($sql, drupflare_tags_prefix());
	$verb = $class['verb'];
	if ($verb === 'SELECT') {
		if ($class['content'] === [] && $class['foreign'] === []) {
			return $sql;
		}
		[$file, $reader] = drupflare_tags_call_site();
		if ($class['foreign'] !== []) {
			drupflare_tags_untaggable('custom-table:' . $class['foreign'][0]);
		}
		if ($class['content'] === []) {
			return $sql;
		}
		if (!drupflare_tags_core_file($file)) {
			drupflare_tags_untaggable('direct-read:' . basename($file));
			return $sql;
		}
		$readers = drupflare_tags_readers();
		if (!isset($readers[$reader])) {
			drupflare_tags_untaggable('unrecognised-reader:' . $reader);
			return $sql;
		}
		$state['readers'][$reader] = true;
		foreach ($readers[$reader] as $tag) {
			drupflare_tags_note($tag);
		}
		return $sql;
	}
	if (in_array($verb, ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'], true)) {
		drupflare_tags_wholesale('schema');
		return $sql;
	}
	if (
		in_array($verb, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true) &&
		$class['content'] !== []
	) {
		[$file] = drupflare_tags_call_site();
		if (!drupflare_tags_core_file($file)) {
			drupflare_tags_wholesale('direct-write:' . basename($file));
		}
	}
	return $sql;
}

function drupflare_tags_prefix(): string
{
	global $wpdb;
	return is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
}

/**
 * The object cache's groups, or null when it is not the stock cache and so cannot be read.
 *
 * @return array<string, array<int|string, mixed>>|null
 */
function drupflare_tags_cache_groups(): ?array
{
	global $wp_object_cache;
	if (!is_object($wp_object_cache) || get_class($wp_object_cache) !== 'WP_Object_Cache') {
		return null;
	}
	try {
		$read = Closure::bind(fn() => $this->cache, $wp_object_cache, 'WP_Object_Cache');
		$groups = $read();
	} catch (Throwable $e) {
		return null;
	}
	return is_array($groups) ? $groups : null;
}

function drupflare_tags_from_cache(): void
{
	$groups = drupflare_tags_cache_groups();
	if ($groups === null) {
		drupflare_tags_untaggable('object-cache-opaque');
		return;
	}
	foreach ($groups as $group => $entries) {
		if (!is_array($entries)) {
			continue;
		}
		$group = (string) $group;
		foreach ($entries as $key => $value) {
			drupflare_tags_from_entry($group, $key, $value, $groups);
		}
	}
}

/**
 * @param array<string, array<int|string, mixed>> $groups
 */
function drupflare_tags_from_entry(
	string $group,
	int|string $key,
	mixed $value,
	array $groups,
): void {
	$id = is_int($key) ? $key : (ctype_digit((string) $key) ? (int) $key : null);
	switch (true) {
		case $group === 'posts' && $id !== null:
		case $group === 'post_meta' && $id !== null:
			drupflare_tags_note('post:' . $id);
			return;
		case $group === 'terms' && $id !== null:
		case $group === 'term_meta' && $id !== null:
			drupflare_tags_note('term:' . $id);
			return;
		case $group === 'users' && $id !== null:
		case $group === 'user_meta' && $id !== null:
			drupflare_tags_note('user:' . $id);
			return;
		case $group === 'comment' && $id !== null:
			drupflare_tags_note('comments:' . drupflare_tags_comment_post($value));
			return;
		case $group === 'comment_meta' && $id !== null:
			drupflare_tags_note(
				'comments:' . drupflare_tags_comment_post($groups['comment'][$id] ?? null),
			);
			return;
		case $group === 'counts':
			drupflare_tags_from_count((string) $key);
			return;
		case $group === 'timeinfo':
			drupflare_tags_note('posts');
			return;
		case $group !== 'link_category_relationships' &&
			preg_match('/_relationships$/', $group) === 1 &&
			$id !== null:
			drupflare_tags_note('post:' . $id);
			return;
	}
}

function drupflare_tags_comment_post(mixed $comment): string
{
	$post = is_object($comment) ? (int) ($comment->comment_post_ID ?? 0) : 0;
	return $post > 0 ? (string) $post : '0';
}

function drupflare_tags_from_count(string $key): void
{
	if (preg_match('/^posts-([A-Za-z0-9_-]+?)(?:_readable)?$/', $key, $m) === 1) {
		drupflare_tags_note('posts:' . drupflare_tags_name($m[1]));
		return;
	}
	if (preg_match('/^comments-(\d+)$/', $key, $m) === 1) {
		drupflare_tags_note($m[1] === '0' ? 'comments' : 'comments:' . $m[1]);
		return;
	}
	if ($key === 'attachments' || str_starts_with($key, 'attachments-')) {
		drupflare_tags_note('posts:attachment');
		return;
	}
	drupflare_tags_untaggable('count:' . drupflare_tags_name($key));
}

function drupflare_tags_from_queries(): void
{
	$state = &drupflare_tags_state();
	foreach ($state['queries'] as $query) {
		$found = is_array($query->posts) ? $query->posts : [];
		foreach ($found as $post) {
			$id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
			if ($id > 0) {
				drupflare_tags_note('post:' . $id);
			}
		}
		// one post answers for itself; anything else could gain a row
		if ($query->is_singular() && $found !== []) {
			continue;
		}
		foreach (drupflare_tags_query_types($query, $query->get('post_type')) as $type) {
			foreach (drupflare_tags_listing_for_type($type) as $tag) {
				drupflare_tags_note($tag);
			}
		}
	}
	foreach ($state['termQueries'] as $query) {
		$taxonomies = $query->query_vars['taxonomy'] ?? [];
		$taxonomies = is_array($taxonomies) ? $taxonomies : [$taxonomies];
		$taxonomies = array_filter(array_map('strval', $taxonomies));
		if ($taxonomies === []) {
			drupflare_tags_note('terms');
		}
		foreach ($taxonomies as $taxonomy) {
			drupflare_tags_note('terms:' . drupflare_tags_name($taxonomy));
		}
	}
	foreach ($state['commentQueries'] as $query) {
		$post = (int) ($query->query_vars['post_id'] ?? 0);
		drupflare_tags_note($post > 0 ? 'comments:' . $post : 'comments');
	}
	if ($state['userQueries'] !== []) {
		drupflare_tags_note('users');
	}
}

/**
 * The tags the page just rendered depends on, or why they cannot be named.
 *
 * @return array{tags: list<string>, untaggable: string|null}
 */
function drupflare_tags_collect(): array
{
	$state = &drupflare_tags_state();
	try {
		drupflare_tags_quietly(static function (): void {
			drupflare_tags_from_cache();
			drupflare_tags_from_queries();
		});
	} catch (Throwable $e) {
		drupflare_tags_untaggable('collect-failed');
	}
	// a request that changed something may have rendered before or after the change
	if ($state['sent'] !== [] || $state['wholesale'] !== []) {
		drupflare_tags_untaggable('request-wrote');
	}
	if ($state['failed']) {
		drupflare_tags_untaggable('host-call-failed');
	}
	$tags = array_keys($state['tags']);
	sort($tags);
	if ($tags === []) {
		drupflare_tags_untaggable('no-tags');
	}
	$reason = $state['reasons'][0] ?? null;
	return [
		'tags' => $reason === null ? $tags : [],
		'untaggable' => $reason,
		'reasons' => $state['reasons'],
		'readers' => array_keys($state['readers']),
	];
}

/**
 * Ends the request's share of the protocol: names the page's tags and tells the host the run is
 * over, so it can judge the writes it saw against the tags announced.
 *
 * @return array{tags: list<string>, untaggable: string|null}
 */
function drupflare_tags_finish(): array
{
	// the settle first: a host that refused it leaves the page untaggable
	drupflare_tags_call(['op' => 'settle']);
	return drupflare_tags_collect();
}

#endregion

#region tags a write invalidates

/**
 * @return list<string>
 */
function drupflare_tags_for_post(int $id, string $type, bool $listing): array
{
	$tags = ['post:' . $id];
	if ($listing) {
		$tags = array_merge($tags, drupflare_tags_listing_for_write($type));
	}
	return $tags;
}

function drupflare_tags_announce_post(mixed $post, bool $listing): void
{
	if (
		!is_object($post) ||
		(int) ($post->ID ?? 0) <= 0 ||
		(string) ($post->post_type ?? '') === ''
	) {
		drupflare_tags_wholesale('post-unknown');
		return;
	}
	$type = (string) $post->post_type;
	if (in_array($type, drupflare_tags_structural_types(), true)) {
		drupflare_tags_wholesale('structural-post');
		return;
	}
	drupflare_tags_announce(drupflare_tags_for_post((int) $post->ID, $type, $listing));
}

function drupflare_tags_on_clean_post_cache(mixed $id, mixed $post = null): void
{
	drupflare_tags_announce_post(
		$post,
		is_object($post) && ($post->post_status ?? '') === 'publish',
	);
}

function drupflare_tags_on_transition_post_status(mixed $new, mixed $old, mixed $post = null): void
{
	if ($new === 'publish' || $old === 'publish') {
		drupflare_tags_announce_post($post, true);
	}
}

function drupflare_tags_on_post_meta(mixed $meta, mixed $post_id): void
{
	drupflare_tags_announce(['post:' . (int) $post_id]);
}

/**
 * @return list<string>
 */
function drupflare_tags_for_term(int $id, string $taxonomy): array
{
	return ['term:' . $id, 'terms:' . drupflare_tags_name($taxonomy), 'terms'];
}

function drupflare_tags_announce_term(int $id, string $taxonomy): void
{
	if ($taxonomy === 'nav_menu') {
		drupflare_tags_wholesale('menu');
		return;
	}
	drupflare_tags_announce(drupflare_tags_for_term($id, $taxonomy));
}

function drupflare_tags_on_term_change(mixed $term_id, mixed $tt_id = 0, mixed $taxonomy = ''): void
{
	drupflare_tags_announce_term((int) $term_id, (string) $taxonomy);
}

/**
 * A term's post count as it stands, or null when it cannot be read.
 */
function drupflare_tags_term_count(int $tt_id): ?int
{
	global $wpdb;
	if (!is_object($wpdb)) {
		return null;
	}
	$count = drupflare_tags_quietly(
		static fn() => $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `count` FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d",
				$tt_id,
			),
		),
	);
	return is_numeric($count) ? (int) $count : null;
}

// WordPress rewrites a term's count on every post save, changed or not, so the count is read
// before and after and only a change is announced
function drupflare_tags_on_edit_term_taxonomy(mixed $tt_id, mixed $taxonomy = ''): void
{
	$state = &drupflare_tags_state();
	$state['counts'][(int) $tt_id] = drupflare_tags_term_count((int) $tt_id);
}

function drupflare_tags_on_edited_term_taxonomy(mixed $tt_id, mixed $taxonomy = ''): void
{
	global $wpdb;
	$state = &drupflare_tags_state();
	$before = $state['counts'][(int) $tt_id] ?? null;
	if ($before !== null && $before === drupflare_tags_term_count((int) $tt_id)) {
		drupflare_tags_quiet('term_taxonomy');
		return;
	}
	$taxonomy = (string) $taxonomy;
	$id = null;
	if (is_object($wpdb)) {
		$id = drupflare_tags_quietly(
			static fn() => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT term_id FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d",
					(int) $tt_id,
				),
			),
		);
	}
	if ($id === null || $id === false) {
		drupflare_tags_wholesale('term-unknown');
		return;
	}
	drupflare_tags_announce_term((int) $id, $taxonomy);
}

function drupflare_tags_on_term_meta(mixed $meta, mixed $term_id): void
{
	drupflare_tags_announce(['term:' . (int) $term_id]);
}

function drupflare_tags_on_relationship(mixed $object_id, mixed $tt, mixed $taxonomy = ''): void
{
	$taxonomy = (string) $taxonomy;
	if ($taxonomy === 'nav_menu') {
		drupflare_tags_wholesale('menu');
		return;
	}
	$post = drupflare_tags_quietly(static fn() => get_post((int) $object_id));
	// the term's own count change is announced by edited_term_taxonomy, and only when it moves
	$tags = ['post:' . (int) $object_id];
	if ($post instanceof WP_Post && $post->post_status === 'publish') {
		$tags = array_merge($tags, drupflare_tags_listing_for_write((string) $post->post_type));
	}
	drupflare_tags_announce($tags);
}

function drupflare_tags_announce_comment(mixed $comment, bool $visible): void
{
	if (!is_object($comment)) {
		drupflare_tags_wholesale('comment-unknown');
		return;
	}
	if (!$visible) {
		drupflare_tags_quiet('comments');
		drupflare_tags_quiet('commentmeta');
		return;
	}
	$post = (int) ($comment->comment_post_ID ?? 0);
	drupflare_tags_announce(['comments:' . $post, 'comments', 'post:' . $post]);
}

function drupflare_tags_visible_comment(mixed $comment): bool
{
	return is_object($comment) && (string) ($comment->comment_approved ?? '') === '1';
}

function drupflare_tags_on_insert_comment(mixed $id, mixed $comment = null): void
{
	drupflare_tags_announce_comment($comment, drupflare_tags_visible_comment($comment));
}

function drupflare_tags_on_transition_comment(mixed $new, mixed $old, mixed $comment = null): void
{
	drupflare_tags_announce_comment($comment, $new === 'approved' || $old === 'approved');
}

function drupflare_tags_on_edit_comment(mixed $id): void
{
	$comment = drupflare_tags_quietly(static fn() => get_comment((int) $id));
	drupflare_tags_announce_comment($comment, drupflare_tags_visible_comment($comment));
}

// trashing or restoring a post moves all its comments in one statement, with no hook per comment
function drupflare_tags_on_post_comments(mixed $post_id): void
{
	drupflare_tags_announce(['comments:' . (int) $post_id, 'comments', 'post:' . (int) $post_id]);
}

function drupflare_tags_on_comment_meta(mixed $meta, mixed $comment_id): void
{
	drupflare_tags_on_edit_comment($comment_id);
}

function drupflare_tags_on_clean_user_cache(mixed $id): void
{
	drupflare_tags_announce(['user:' . (int) $id]);
}

function drupflare_tags_on_user_listing(mixed $id): void
{
	drupflare_tags_announce(['user:' . (int) $id, 'users']);
}

function drupflare_tags_on_user_meta(mixed $meta, mixed $user_id, mixed $key = ''): void
{
	if (drupflare_tags_quiet_user_meta((string) $key)) {
		drupflare_tags_quiet('usermeta');
		return;
	}
	drupflare_tags_announce(['user:' . (int) $user_id]);
}

function drupflare_tags_announce_option(string $name): void
{
	if (drupflare_tags_quiet_option($name)) {
		drupflare_tags_quiet('options');
		return;
	}
	if (drupflare_tags_structural_option($name)) {
		drupflare_tags_wholesale('option:' . drupflare_tags_name($name));
		return;
	}
	drupflare_tags_announce(['option:' . drupflare_tags_name($name)]);
}

function drupflare_tags_on_option(mixed $name): void
{
	drupflare_tags_announce_option((string) $name);
}

function drupflare_tags_on_structural(): void
{
	drupflare_tags_wholesale('structural');
}

#endregion

/**
 * One row per hook that can change what a stored page shows: the hook, its handler and how many
 * arguments the handler takes.
 *
 * @return list<array{string, string, int}>
 */
function drupflare_tags_hooks(): array
{
	return [
		['clean_post_cache', 'drupflare_tags_on_clean_post_cache', 2],
		['transition_post_status', 'drupflare_tags_on_transition_post_status', 3],
		['added_post_meta', 'drupflare_tags_on_post_meta', 2],
		['updated_post_meta', 'drupflare_tags_on_post_meta', 2],
		['deleted_post_meta', 'drupflare_tags_on_post_meta', 2],
		['added_term_relationship', 'drupflare_tags_on_relationship', 3],
		['deleted_term_relationships', 'drupflare_tags_on_relationship', 3],
		['created_term', 'drupflare_tags_on_term_change', 3],
		['edited_term', 'drupflare_tags_on_term_change', 3],
		['delete_term', 'drupflare_tags_on_term_change', 3],
		['edit_term_taxonomy', 'drupflare_tags_on_edit_term_taxonomy', 2],
		['edited_term_taxonomy', 'drupflare_tags_on_edited_term_taxonomy', 2],
		['added_term_meta', 'drupflare_tags_on_term_meta', 2],
		['updated_term_meta', 'drupflare_tags_on_term_meta', 2],
		['deleted_term_meta', 'drupflare_tags_on_term_meta', 2],
		['wp_insert_comment', 'drupflare_tags_on_insert_comment', 2],
		['transition_comment_status', 'drupflare_tags_on_transition_comment', 3],
		['deleted_comment', 'drupflare_tags_on_insert_comment', 2],
		['edit_comment', 'drupflare_tags_on_edit_comment', 1],
		['trashed_post_comments', 'drupflare_tags_on_post_comments', 1],
		['untrashed_post_comments', 'drupflare_tags_on_post_comments', 1],
		['added_comment_meta', 'drupflare_tags_on_comment_meta', 2],
		['updated_comment_meta', 'drupflare_tags_on_comment_meta', 2],
		['deleted_comment_meta', 'drupflare_tags_on_comment_meta', 2],
		['clean_user_cache', 'drupflare_tags_on_clean_user_cache', 1],
		['user_register', 'drupflare_tags_on_user_listing', 1],
		['deleted_user', 'drupflare_tags_on_user_listing', 1],
		['added_user_meta', 'drupflare_tags_on_user_meta', 3],
		['updated_user_meta', 'drupflare_tags_on_user_meta', 3],
		['deleted_user_meta', 'drupflare_tags_on_user_meta', 3],
		['added_option', 'drupflare_tags_on_option', 1],
		['updated_option', 'drupflare_tags_on_option', 1],
		['deleted_option', 'drupflare_tags_on_option', 1],
		['switch_theme', 'drupflare_tags_on_structural', 0],
		['wp_create_nav_menu', 'drupflare_tags_on_structural', 0],
		['wp_update_nav_menu', 'drupflare_tags_on_structural', 0],
		['wp_delete_nav_menu', 'drupflare_tags_on_structural', 0],
		['wp_update_nav_menu_item', 'drupflare_tags_on_structural', 0],
		['customize_save_after', 'drupflare_tags_on_structural', 0],
	];
}

function drupflare_tags_register(): void
{
	foreach (drupflare_tags_hooks() as [$hook, $handler, $args]) {
		add_action($hook, $handler, 10, $args);
	}
	// last, so a pre-query answer another plugin gave is visible
	add_filter('posts_pre_query', 'drupflare_tags_on_posts_pre_query', 99999, 2);
	add_filter('terms_pre_query', 'drupflare_tags_on_terms_pre_query', 99999, 2);
	add_filter('comments_pre_query', 'drupflare_tags_on_comments_pre_query', 99999, 2);
	add_filter('users_pre_query', 'drupflare_tags_on_users_pre_query', 99999, 2);
	add_filter('pre_option', 'drupflare_tags_on_pre_option', 0, 2);
	// the statement as it will run, after every other filter has had it
	add_filter('query', 'drupflare_tags_on_query', 99999);
}
