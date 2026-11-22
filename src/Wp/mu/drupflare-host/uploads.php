<?php

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;

const DRUPFLARE_UPLOADS_ROOT = 'public://wp-content/uploads';
const DRUPFLARE_UPLOADS_DEFAULT_MAX_BYTES = 10485760;
const DRUPFLARE_UPLOADS_CHUNKED_READ_ABOVE = 800000;

/** the most bytes one upload may hold; the file is buffered whole in PHP before it is stored */
function drupflare_uploads_max_bytes(): int
{
	return defined('DRUPFLARE_UPLOAD_MAX_BYTES')
		? max(1, (int) DRUPFLARE_UPLOAD_MAX_BYTES)
		: DRUPFLARE_UPLOADS_DEFAULT_MAX_BYTES;
}

/** whether the host installed the durable file store, the only place an upload can live */
function drupflare_uploads_available(): bool
{
	return Host::has('cfwFileWrite') && Host::has('cfwFileRead');
}

/** the path after the scheme, or null when it climbs out of its directory */
function drupflare_uploads_target(string $uri): ?string
{
	$parts = explode('://', $uri, 2);
	$segments = explode('/', $parts[1] ?? '');
	return in_array('..', $segments, true) ? null : ltrim($parts[1] ?? '', '/');
}

/**
 * The `public://` scheme over the host file store, which is where uploads live.
 *
 * WordPress writes to wp-content/uploads with ordinary file functions, and the interpreter's
 * filesystem lasts one request. This wrapper sends the same calls to the file store the Drupal
 * wrapper uses, so a file is committed when the write returns. It does not stream: a write buffers
 * until the handle closes and a read fetches the whole file.
 */
final class Drupflare_Uploads_Stream
{
	/** @var resource|null */
	public $context;

	private string $buffer = '';
	private int $position = 0;
	private string $uri = '';
	private bool $writable = false;
	private bool $dirty = false;
	/** @var string[] */
	private array $entries = [];
	private int $entryAt = 0;
	/** @var array<string, true> */
	private static array $made = [];

	public function stream_open($path, $mode, $options, &$opened_path): bool
	{
		if (drupflare_uploads_target($path) === null) {
			return $this->fail($options, $path . ' climbs out of its directory');
		}
		$this->uri = $path;
		$this->position = 0;
		$this->buffer = '';
		$this->dirty = false;
		$base = rtrim($mode, 'bt+');
		$this->writable = $base !== 'r' || str_contains($mode, '+');

		if ($base === 'r' || $base === 'a' || str_contains($mode, '+')) {
			$read = $this->read($path);
			if ($read === false) {
				return $this->fail($options, $path . ' could not be read');
			}
			if ($read === null && $base === 'r') {
				return $this->fail($options, $path . ' does not exist');
			}
			$this->buffer = $read ?? '';
		}
		if ($base === 'a') {
			$this->position = strlen($this->buffer);
		}
		if ($base === 'w') {
			$this->buffer = '';
			$this->dirty = true;
		}
		if ($opened_path !== null) {
			$opened_path = $path;
		}
		return true;
	}

	/** the file's bytes, null when it is absent, false when it exists and cannot be read now */
	private function read(string $path): string|false|null
	{
		$stat = Host::call('cfwFileStat', ['uri' => $path]);
		if (($stat['ok'] ?? false) !== true) {
			return null;
		}
		$size = (int) ($stat['size'] ?? 0);
		$chunks = (int) ($stat['chunks'] ?? 0);
		if ($size >= DRUPFLARE_UPLOADS_CHUNKED_READ_ABOVE && $chunks > 0) {
			$assembled = '';
			for ($seq = 0; $seq < $chunks; $seq++) {
				$part = Host::call('cfwFileRead', ['uri' => $path, 'seq' => $seq]);
				if (($part['ok'] ?? false) !== true || !array_key_exists('b64', $part)) {
					return false;
				}
				$assembled .= (string) base64_decode((string) $part['b64'], true);
			}
			return $assembled;
		}
		$reply = Host::call('cfwFileRead', ['uri' => $path]);
		if (($reply['ok'] ?? false) !== true || !array_key_exists('b64', $reply)) {
			return false;
		}
		return (string) base64_decode((string) $reply['b64'], true);
	}

	public function stream_read($count): string
	{
		$chunk = substr($this->buffer, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}

	public function stream_write($data): int
	{
		if (!$this->writable) {
			return 0;
		}
		if ($this->position > strlen($this->buffer)) {
			$this->buffer = str_pad($this->buffer, $this->position, "\0");
		}
		$this->buffer = substr_replace($this->buffer, $data, $this->position, strlen($data));
		$this->position += strlen($data);
		$this->dirty = true;
		return strlen($data);
	}

	public function stream_flush(): bool
	{
		if (!$this->dirty) {
			return true;
		}
		if (strlen($this->buffer) > drupflare_uploads_max_bytes()) {
			return false;
		}
		$type = function_exists('wp_check_filetype') ? wp_check_filetype($this->uri)['type'] : null;
		$reply = Host::call('cfwFileWrite', [
			'uri' => $this->uri,
			'b64' => base64_encode($this->buffer),
			'mime' => $type ?: null,
		]);
		$ok = ($reply['ok'] ?? false) === true;
		if ($ok) {
			$this->dirty = false;
		}
		return $ok;
	}

	public function stream_eof(): bool
	{
		return $this->position >= strlen($this->buffer);
	}

	public function stream_tell(): int
	{
		return $this->position;
	}

	public function stream_seek($offset, $whence = SEEK_SET): bool
	{
		$target = match ($whence) {
			SEEK_SET => $offset,
			SEEK_CUR => $this->position + $offset,
			SEEK_END => strlen($this->buffer) + $offset,
			default => -1,
		};
		if ($target < 0) {
			return false;
		}
		$this->position = $target;
		return true;
	}

	public function stream_truncate(int $new_size): bool
	{
		if (!$this->writable) {
			return false;
		}
		$this->buffer = str_pad(substr($this->buffer, 0, $new_size), $new_size, "\0");
		$this->dirty = true;
		return true;
	}

	/** @return array<int|string, int> */
	public function stream_stat(): array
	{
		return self::stat(strlen($this->buffer), 0, 0100666);
	}

	public function stream_close(): void
	{
		if (!$this->stream_flush()) {
			Degradation::record(
				'wordpress uploads',
				'a buffered upload could not be committed to the file store when its handle closed, so those bytes are lost',
			);
		}
		$this->buffer = '';
		$this->position = 0;
		$this->dirty = false;
	}

	/** @return array<int|string, int>|false */
	public function url_stat($path, $flags): array|false
	{
		if (drupflare_uploads_target($path) === null) {
			return false;
		}
		if (preg_match('#^[a-z][a-z0-9+.-]*://$#i', $path)) {
			return self::stat(0, 0, 040755);
		}
		$reply = Host::call('cfwFileStat', ['uri' => $path]);
		if (($reply['ok'] ?? false) === true) {
			return self::stat(
				(int) ($reply['size'] ?? 0),
				(int) ($reply['modified'] ?? 0),
				0100666,
			);
		}
		if (isset(self::$made[rtrim($path, '/')])) {
			return self::stat(0, 0, 040755);
		}
		$listing = Host::call('cfwFileList', ['prefix' => rtrim($path, '/') . '/', 'limit' => 1]);
		return is_array($listing['files'] ?? null) && $listing['files'] !== []
			? self::stat(0, 0, 040755)
			: false;
	}

	public function unlink($path): bool
	{
		return drupflare_uploads_target($path) !== null &&
			(Host::call('cfwFileDelete', ['uri' => $path])['ok'] ?? false) === true;
	}

	public function rename($path_from, $path_to): bool
	{
		if (
			drupflare_uploads_target($path_from) === null ||
			drupflare_uploads_target($path_to) === null
		) {
			return false;
		}
		$reply = Host::call('cfwFileRename', [
			'from' => $path_from,
			'to' => $path_to,
			'overwrite' => true,
		]);
		return ($reply['ok'] ?? false) === true;
	}

	public function mkdir($path, $mode, $options): bool
	{
		if (drupflare_uploads_target($path) === null) {
			return false;
		}
		for ($dir = rtrim($path, '/'); str_contains($dir, '://'); $dir = dirname($dir)) {
			self::$made[$dir] = true;
		}
		return true;
	}

	public function rmdir($path, $options): bool
	{
		unset(self::$made[rtrim($path, '/')]);
		$listing = Host::call('cfwFileList', ['prefix' => rtrim($path, '/') . '/']);
		foreach (is_array($listing['files'] ?? null) ? $listing['files'] : [] as $file) {
			$uri = is_array($file) ? (string) ($file['uri'] ?? '') : '';
			if ($uri !== '') {
				Host::call('cfwFileDelete', ['uri' => $uri]);
			}
		}
		return true;
	}

	public function dir_opendir($path, $options): bool
	{
		$prefix = rtrim($path, '/') . '/';
		$listing = Host::call('cfwFileList', ['prefix' => $prefix]);
		$names = [];
		foreach (is_array($listing['files'] ?? null) ? $listing['files'] : [] as $file) {
			$uri = is_array($file) ? (string) ($file['uri'] ?? '') : '';
			if ($uri === '' || !str_starts_with($uri, $prefix)) {
				continue;
			}
			$rest = substr($uri, strlen($prefix));
			$slash = strpos($rest, '/');
			$names[$slash === false ? $rest : substr($rest, 0, $slash)] = true;
		}
		$this->entries = array_keys($names);
		sort($this->entries);
		$this->entryAt = 0;
		return true;
	}

	public function dir_readdir(): string|false
	{
		return $this->entries[$this->entryAt++] ?? false;
	}

	public function dir_rewinddir(): bool
	{
		$this->entryAt = 0;
		return true;
	}

	public function dir_closedir(): bool
	{
		$this->entries = [];
		return true;
	}

	public function stream_metadata($path, $option, $value): bool
	{
		return false;
	}

	public function stream_lock($operation): bool
	{
		return false;
	}

	public function stream_set_option($option, $arg1, $arg2): bool
	{
		return false;
	}

	/** @return array<int|string, int> */
	private static function stat(int $size, int $mtimeMs, int $mode): array
	{
		$mtime = (int) floor($mtimeMs / 1000);
		$values = [
			'dev' => 0,
			'ino' => 0,
			'mode' => $mode,
			'nlink' => 1,
			'uid' => 0,
			'gid' => 0,
			'rdev' => 0,
			'size' => $size,
			'atime' => $mtime,
			'mtime' => $mtime,
			'ctime' => $mtime,
			'blksize' => -1,
			'blocks' => -1,
		];
		return array_merge(array_values($values), $values);
	}

	private function fail(int $options, string $message): bool
	{
		if ($options & STREAM_REPORT_ERRORS) {
			trigger_error($message, E_USER_WARNING);
		}
		return false;
	}
}

/**
 * The `private://` scheme, which this host does not serve for WordPress.
 *
 * A path under it fails by name instead of landing on the interpreter's own filesystem, where it
 * would vanish with the request.
 */
final class Drupflare_Private_Stream
{
	/** @var resource|null */
	public $context;

	private static function refuse(): bool
	{
		Degradation::record(
			'wordpress private uploads',
			'WordPress has no private file area on this host, so a private:// path is refused instead of written where it would be lost',
		);
		trigger_error('private:// uploads are not supported on this host', E_USER_WARNING);
		return false;
	}

	public function stream_open($path, $mode, $options, &$opened_path): bool
	{
		return self::refuse();
	}

	public function url_stat($path, $flags): array|false
	{
		return false;
	}

	public function mkdir($path, $mode, $options): bool
	{
		return self::refuse();
	}

	public function unlink($path): bool
	{
		return self::refuse();
	}

	public function rename($path_from, $path_to): bool
	{
		return self::refuse();
	}
}

/** takes over public:// and private:// when the host file store is there */
function drupflare_uploads_register(): void
{
	if (!drupflare_uploads_available()) {
		return;
	}
	foreach (
		['public' => Drupflare_Uploads_Stream::class, 'private' => Drupflare_Private_Stream::class]
		as $scheme => $class
	) {
		if (in_array($scheme, stream_get_wrappers(), true)) {
			@stream_wrapper_unregister($scheme);
		}
		stream_wrapper_register($scheme, $class, 0);
	}
}

/**
 * Points wp-content/uploads at the file store.
 *
 * @param array<string, mixed>|mixed $dirs
 *
 * @return array<string, mixed>|mixed
 */
function drupflare_uploads_dir($dirs)
{
	if (!is_array($dirs) || !drupflare_uploads_available()) {
		return $dirs;
	}
	$dirs['basedir'] = DRUPFLARE_UPLOADS_ROOT;
	$dirs['path'] = DRUPFLARE_UPLOADS_ROOT . ($dirs['subdir'] ?? '');
	return $dirs;
}

/** the limit the admin shows, held to what this host buffers */
function drupflare_uploads_size_limit($size)
{
	return min((int) $size, drupflare_uploads_max_bytes());
}

/**
 * Moves an upload the host parsed, which move_uploaded_file refuses.
 *
 * PHP moves a file only when its path is in the table of this request's uploads, and only a real
 * POST SAPI fills it. The request parser lists the files it wrote in $GLOBALS['__cfw_uploads'], so
 * this accepts the same set PHP would. A sideload is left to WordPress, which copies it.
 *
 * @param null|bool $moved
 * @param array<string, mixed> $file
 */
function drupflare_uploads_pre_move($moved, $file, $new_file, $type)
{
	if ($moved !== null || !str_starts_with((string) $new_file, 'public://')) {
		return $moved;
	}
	$tmp = (string) ($file['tmp_name'] ?? '');
	if (!isset($GLOBALS['__cfw_uploads'][$tmp])) {
		return null;
	}
	if (!@copy($tmp, $new_file)) {
		return false;
	}
	@unlink($tmp);
	unset($GLOBALS['__cfw_uploads'][$tmp]);
	return true;
}
