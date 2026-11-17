<?php

namespace Drupflare\Wp;

/**
 * Row values cross as int, float, string or null, as SQLite returns them. BridgeWpdb turns every
 * non-null value into the text mysqli would have returned, so the host need not stringify.
 *
 * @phpstan-type Rows array{ok: true, kind: 'rows', columns: list<string>, rows: list<list<mixed>>}
 * @phpstan-type Write array{ok: true, kind: 'write', affected: int, insertId: int}
 * @phpstan-type None array{ok: true, kind: 'none'}
 * @phpstan-type Failure array{ok: false, errno: int, sqlstate: string, error: string, refused?: string}
 * @phpstan-type Reply Rows|Write|None|Failure
 */
final class Bridge
{
	public const HOST_FUNCTION = 'cfwMysqlExec';

	public const ER_UNKNOWN_ERROR = 1105;

	public const ER_INVALID_CHARACTER_STRING = 1300;

	public const CR_CONNECTION_ERROR = 2002;

	// site health reads this as the client library version
	public string $client_info = 'drupflare-bridge';

	public function available(): bool
	{
		return $this->hostFunction() !== null;
	}

	/**
	 * @return Reply
	 */
	public function exec(string $sql): array
	{
		$fn = $this->hostFunction();
		if ($fn === null) {
			return self::failure(
				self::CR_CONNECTION_ERROR,
				self::HOST_FUNCTION . ' is not installed on the module',
			);
		}
		if (preg_match('//u', $sql) !== 1) {
			return self::failure(
				self::ER_INVALID_CHARACTER_STRING,
				'Invalid utf8mb4 character string',
			);
		}
		return self::decode(cfw_call($fn, ['sql' => $sql]));
	}

	private function hostFunction(): mixed
	{
		if (!function_exists('cfw_host') || !function_exists('cfw_call')) {
			return null;
		}
		return cfw_host(self::HOST_FUNCTION);
	}

	/**
	 * @param array<string, mixed> $reply
	 *
	 * @return Reply
	 */
	private static function decode(array $reply): array
	{
		if (($reply['ok'] ?? false) !== true) {
			return self::decodeFailure($reply);
		}
		$kind = $reply['kind'] ?? null;
		if ($kind === 'none') {
			return ['ok' => true, 'kind' => 'none'];
		}
		if ($kind === 'write') {
			return [
				'ok' => true,
				'kind' => 'write',
				'affected' => (int) ($reply['affected'] ?? 0),
				'insertId' => (int) ($reply['insertId'] ?? 0),
			];
		}
		$columns = $reply['columns'] ?? null;
		$rows = $reply['rows'] ?? null;
		if ($kind !== 'rows' || !is_array($columns) || !is_array($rows)) {
			return self::failure(
				self::ER_UNKNOWN_ERROR,
				'malformed reply from ' . self::HOST_FUNCTION . ': ' . json_encode($kind),
			);
		}
		$list = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				return self::failure(
					self::ER_UNKNOWN_ERROR,
					'malformed row from ' . self::HOST_FUNCTION,
				);
			}
			foreach ($row as $cell) {
				if (is_array($cell) || is_object($cell)) {
					return self::failure(
						self::ER_UNKNOWN_ERROR,
						'malformed cell from ' . self::HOST_FUNCTION,
					);
				}
			}
			$list[] = array_values($row);
		}
		return [
			'ok' => true,
			'kind' => 'rows',
			'columns' => array_map('strval', array_values($columns)),
			'rows' => $list,
		];
	}

	/**
	 * @param array<string, mixed> $reply
	 *
	 * @return Failure
	 */
	private static function decodeFailure(array $reply): array
	{
		$refused = $reply['refused'] ?? null;
		$refused = is_string($refused) && $refused !== '' ? $refused : null;
		$error = $reply['error'] ?? null;
		if (!is_string($error) || $error === '') {
			$error =
				$refused !== null
					? 'Statement refused: ' . $refused
					: 'Unknown error from ' . self::HOST_FUNCTION;
		}
		$sqlstate = $reply['sqlstate'] ?? null;
		$failure = self::failure(
			is_numeric($reply['errno'] ?? null) ? (int) $reply['errno'] : self::ER_UNKNOWN_ERROR,
			$error,
			is_string($sqlstate) && $sqlstate !== '' ? $sqlstate : 'HY000',
		);
		if ($refused !== null) {
			$failure['refused'] = $refused;
		}
		return $failure;
	}

	/**
	 * @return Failure
	 */
	private static function failure(int $errno, string $error, string $sqlstate = 'HY000'): array
	{
		return ['ok' => false, 'errno' => $errno, 'sqlstate' => $sqlstate, 'error' => $error];
	}
}
