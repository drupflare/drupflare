<?php

namespace Drupflare\Wp;

use SensitiveParameter;
use stdClass;
use WP_Error;
use wpdb;

if (!class_exists(Bridge::class, false)) {
	require_once __DIR__ . '/Bridge.php';
}

/**
 * @phpstan-import-type Reply from Bridge
 */
class BridgeWpdb extends wpdb
{
	private const ESCAPES = [
		"\0" => '\\0',
		"\n" => '\\n',
		"\r" => '\\r',
		'\\' => '\\\\',
		"'" => "\\'",
		'"' => '\\"',
		"\x1a" => '\\Z',
	];

	protected const DEFAULT_SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

	protected const FALLBACK_SERVER_INFO = '8.0.17-drupflare';

	protected Bridge $bridge;

	protected bool $linked = false;

	protected string $linkError = '';

	protected int $linkErrno = 0;

	protected ?string $serverInfo = null;

	/** @var list<string> */
	protected array $colNames = [];

	public function __construct(
		$dbuser,
		#[SensitiveParameter] $dbpassword,
		$dbname,
		$dbhost,
		?Bridge $bridge = null,
	) {
		$this->bridge = $bridge ?? new Bridge();
		add_filter('pre_get_table_charset', [$this, 'filterTableCharset']);
		add_filter('pre_get_col_charset', [$this, 'filterColumnCharset']);
		parent::__construct($dbuser, $dbpassword, $dbname, $dbhost);
	}

	public function filterTableCharset($charset)
	{
		return $charset ?? 'utf8mb4';
	}

	public function filterColumnCharset($charset)
	{
		return $charset ?? 'utf8mb4';
	}

	public function determine_charset($charset, $collate)
	{
		if (!$charset || $charset === 'utf8') {
			$charset = 'utf8mb4';
		}

		if ($charset === 'utf8mb4') {
			if (!$collate || $collate === 'utf8_general_ci') {
				$collate = 'utf8mb4_unicode_ci';
			} else {
				$collate = str_replace('utf8_', 'utf8mb4_', $collate);
			}
		}

		if ($this->has_cap('utf8mb4_520') && $collate === 'utf8mb4_unicode_ci') {
			$collate = 'utf8mb4_unicode_520_ci';
		}

		return compact('charset', 'collate');
	}

	public function set_charset($dbh, $charset = null, $collate = null) {}

	public function set_sql_mode($modes = [])
	{
		if (empty($modes)) {
			$modes = explode(',', static::DEFAULT_SQL_MODE);
		}

		$modes = array_change_key_case($modes, CASE_UPPER);
		$incompatible = (array) apply_filters('incompatible_sql_modes', $this->incompatible_modes);

		foreach ($modes as $i => $mode) {
			if (in_array($mode, $incompatible, true)) {
				unset($modes[$i]);
			}
		}

		$this->bridge->exec("SET SESSION sql_mode='" . implode(',', $modes) . "'");
	}

	public function select($db, $dbh = null) {}

	public function _real_escape($data)
	{
		if (!is_scalar($data)) {
			return '';
		}

		return $this->add_placeholder_escape(strtr((string) $data, self::ESCAPES));
	}

	public function print_error($str = '')
	{
		return parent::print_error($str ?: $this->linkError ?: 'Unknown database error');
	}

	public function flush()
	{
		parent::flush();
		$this->colNames = [];
	}

	public function db_connect($allow_bail = true)
	{
		$this->is_mysql = true;

		if (!$this->bridge->available()) {
			$this->dbh = null;
			if (!$allow_bail) {
				return false;
			}

			wp_load_translations_early();

			if (file_exists(WP_CONTENT_DIR . '/db-error.php')) {
				require_once WP_CONTENT_DIR . '/db-error.php';
				die();
			}

			$this->bail(
				$this->unavailableMessage('Error establishing a database connection'),
				'db_connect_fail',
			);

			return false;
		}

		$this->dbh = $this->bridge;

		if (!$this->linked) {
			$this->init_charset();
		}

		$this->linked = true;
		$this->ready = true;
		$this->set_sql_mode();

		return true;
	}

	public function check_connection($allow_bail = true)
	{
		if (!empty($this->dbh) && $this->linkErrno !== 2006 && $this->bridge->available()) {
			return true;
		}

		if ($this->db_connect(false)) {
			return true;
		}

		if (did_action('template_redirect') || !$allow_bail) {
			return false;
		}

		wp_load_translations_early();

		$this->bail(
			$this->unavailableMessage('Error reconnecting to the database'),
			'db_connect_fail',
		);

		dead_db();
	}

	public function query($query)
	{
		if (!$this->ready) {
			$this->check_current_query = true;
			return false;
		}

		$query = apply_filters('query', $query);

		if (!$query) {
			$this->insert_id = 0;
			return false;
		}

		$this->flush();

		$this->func_call = "\$db->query(\"$query\")";

		if ($this->check_current_query && !$this->check_ascii($query)) {
			$stripped_query = $this->strip_invalid_text_from_query($query);
			$this->flush();
			if ($stripped_query !== $query) {
				$this->insert_id = 0;
				$this->last_query = $query;

				wp_load_translations_early();

				$this->last_error = __(
					'WordPress database error: Could not perform query because it contains invalid data.',
				);

				return false;
			}
		}

		$this->check_current_query = true;

		$this->last_query = $query;

		$reply = $this->run($query);

		if (empty($this->dbh) || $this->linkErrno === 2006) {
			if ($this->check_connection()) {
				$reply = $this->run($query);
			} else {
				$this->insert_id = 0;
				return false;
			}
		}

		$this->last_error =
			$this->dbh instanceof Bridge
				? $this->linkError
				: __('Unable to retrieve the error message from the database server');

		if ($this->last_error) {
			if ($this->insert_id && preg_match('/^\s*(insert|replace)\s/i', $query)) {
				$this->insert_id = 0;
			}

			$this->print_error();
			return false;
		}

		$reply ??= ['ok' => true, 'kind' => 'none'];

		if (preg_match('/^\s*(create|alter|truncate|drop)\s/i', $query)) {
			return true;
		}

		if (preg_match('/^\s*(insert|delete|update|replace)\s/i', $query)) {
			$this->rows_affected = $reply['kind'] === 'write' ? $reply['affected'] : 0;

			if (preg_match('/^\s*(insert|replace)\s/i', $query)) {
				$this->insert_id = $reply['kind'] === 'write' ? $reply['insertId'] : 0;
			}

			return $this->rows_affected;
		}

		$num_rows = 0;

		if ($reply['kind'] === 'rows') {
			$this->colNames = $reply['columns'];
			foreach ($reply['rows'] as $values) {
				$row = new stdClass();
				foreach ($reply['columns'] as $i => $name) {
					$row->{$name} = self::text($values[$i] ?? null);
				}
				$this->last_result[$num_rows] = $row;
				++$num_rows;
			}
		}

		$this->num_rows = $num_rows;

		return $num_rows;
	}

	public function has_cap($db_cap)
	{
		return match (strtolower($db_cap)) {
			'collation',
			'group_concat',
			'subqueries',
			'set_charset',
			'utf8mb4',
			'utf8mb4_520',
			'identifier_placeholders'
				=> true,
			default => false,
		};
	}

	public function db_server_info()
	{
		if ($this->serverInfo === null) {
			$reply = $this->bridge->exec('SELECT VERSION()');
			$value =
				$reply['ok'] && $reply['kind'] === 'rows' ? $reply['rows'][0][0] ?? null : null;
			$this->serverInfo =
				is_string($value) && $value !== '' ? $value : static::FALLBACK_SERVER_INFO;
		}

		return $this->serverInfo;
	}

	public function bail($message, $error_code = '500')
	{
		if ($this->show_errors) {
			if ($this->linkError !== '') {
				$message = '<p><code>' . $this->linkError . "</code></p>\n" . $message;
			}

			wp_die($message);
		} else {
			if (class_exists('WP_Error', false)) {
				$this->error = new WP_Error($error_code, $message);
			} else {
				$this->error = $message;
			}

			return false;
		}
	}

	public function close()
	{
		if (!$this->dbh) {
			return false;
		}

		$this->dbh = null;
		$this->ready = false;
		$this->linked = false;

		return true;
	}

	public function get_caller()
	{
		$frames = wp_debug_backtrace_summary(null, 0, false);
		$kept = array_filter(
			$frames,
			static fn(string $frame): bool => !str_starts_with($frame, 'wpdb->') &&
				!str_starts_with($frame, self::class . '->'),
		);

		return implode(', ', array_reverse($kept));
	}

	protected function load_col_info()
	{
		if ($this->col_info) {
			return;
		}

		foreach ($this->colNames as $i => $name) {
			$this->col_info[$i] = (object) [
				'name' => $name,
				'orgname' => $name,
				'table' => null,
				'orgtable' => null,
				'def' => null,
				'db' => null,
				'catalog' => null,
				'max_length' => null,
				'length' => null,
				'charsetnr' => null,
				'flags' => null,
				'type' => null,
				'decimals' => null,
			];
		}
	}

	public static function text(int|float|string|bool|null $value): ?string
	{
		if ($value === null || is_string($value)) {
			return $value;
		}

		if (is_float($value)) {
			return self::double($value);
		}

		return (string) (int) $value;
	}

	private static function double(float $value): string
	{
		if (is_nan($value) || is_infinite($value)) {
			return (string) $value;
		}

		$negative = fdiv(1.0, $value) < 0;
		if ($value === 0.0) {
			return $negative ? '-0' : '0';
		}

		for ($precision = 0; $precision < 17; $precision++) {
			$shortest = sprintf('%.' . $precision . 'e', $value);
			if ((float) $shortest === $value) {
				break;
			}
		}

		[$mantissa, $exponent] = explode('e', $shortest);
		$exponent = (int) $exponent;
		$digits = str_replace(['-', '.'], '', $mantissa);
		$length = strlen($digits);

		if ($exponent < -15 || ($exponent > 14 && $length <= $exponent + 1)) {
			$text = $digits[0] . ($length > 1 ? '.' . substr($digits, 1) : '') . 'e' . $exponent;
		} elseif ($exponent < 0) {
			$text = '0.' . str_repeat('0', -$exponent - 1) . $digits;
		} elseif ($length <= $exponent + 1) {
			$text = $digits . str_repeat('0', $exponent + 1 - $length);
		} else {
			$text = substr($digits, 0, $exponent + 1) . '.' . substr($digits, $exponent + 1);
		}

		return ($negative ? '-' : '') . $text;
	}

	/**
	 * @return Reply|null
	 */
	protected function run(string $query): ?array
	{
		$timed = defined('SAVEQUERIES') && SAVEQUERIES;

		if ($timed) {
			$this->timer_start();
		}

		$reply = null;

		if (!empty($this->dbh)) {
			$reply = $this->bridge->exec($query);
			$this->linkError = $reply['ok'] ? '' : $reply['error'];
			$this->linkErrno = $reply['ok'] ? 0 : $reply['errno'];
		}

		++$this->num_queries;

		if ($timed) {
			$this->log_query(
				$query,
				$this->timer_stop(),
				$this->get_caller(),
				$this->time_start,
				[],
			);
		}

		return $reply;
	}

	protected function unavailableMessage(string $heading): string
	{
		return '<h1>' .
			__($heading) .
			"</h1>\n<p>" .
			sprintf(
				__('The database bridge function %s is not installed on this runtime.'),
				'<code>' . Bridge::HOST_FUNCTION . '</code>',
			) .
			"</p>\n";
	}
}

global $wpdb;

$wpdb = new BridgeWpdb(
	defined('DB_USER') ? DB_USER : '',
	defined('DB_PASSWORD') ? DB_PASSWORD : '',
	defined('DB_NAME') ? DB_NAME : '',
	defined('DB_HOST') ? DB_HOST : '',
);
