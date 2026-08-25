<?php

declare(strict_types=1);

namespace Drupal\drupflare\StreamWrapper;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;

/**
 * A `public://` and `private://` stream wrapper backed by the Durable Object's own SQL.
 *
 * Drupal's file system is MEMFS, which is isolate-local and ephemeral. An upload
 * therefore lives exactly as long as the isolate that received it, while the `file_managed` row
 * describing it is written to durable storage and survives -- so a site accumulates entities that
 * point at nothing. That is not a performance characteristic, it is "uploads do not work", and it
 * blocks every media-shaped part of Drupal.
 *
 * Fully synchronous, unlike HttpsStreamWrapper: PHP cannot await, so a capability
 * that needs the network has to be split into a queue and a drain (see `installCapabilities()` in
 * the host). `ctx.storage.sql` needs no await at all from inside the Durable Object, so every call
 * here returns a real, committed result. That is the entire reason the host stores files in DO SQL
 * and treats R2 as an offload rather than as the durable copy: it is the only arrangement in which
 * a synchronous stream wrapper can tell PHP the truth about whether a write happened.
 *
 * It does not stream. A write buffers in memory until
 * `stream_flush()`/`stream_close()` and lands as one host call; a read fetches the whole file on
 * open. Incremental streaming would need to suspend the interpreter mid-call, which requires JSPI.
 * The host stores chunked, so a large read is divisible on ITS side -- what is not divisible is a
 * single PHP `fread()`, which is a different constraint from the one the chunking solves.
 *
 * Registered by the HOST, from `src/drupal/site-php.js`, and NOT from `drupflare.module` even
 * though that file registers the http/https wrapper. `public` and `private` are core's schemes:
 * StreamWrapperManager::register() runs at DrupalKernel.php:616, three lines after modules load,
 * and registerWrapper() unregisters a scheme before re-registering it -- so a claim made from a
 * module file would be replaced by PublicStream and would look like it worked. Pointing those two
 * schemes here is a container change with a product decision attached, because it moves where a
 * site's files live.
 */
class CfwFileStreamWrapper implements StreamWrapperInterface
{
	/**
	 * Schemes this wrapper takes over.
	 */
	public const SCHEMES = ['public', 'private'];

	/**
	 * The scheme this instance was tagged for, set by the service definition.
	 *
	 * One class serves both `public` and `private` because the storage is identical; only the
	 * visibility differs, and visibility is `getType()`'s answer rather than a storage concern.
	 */
	protected string $scheme = 'public';

	/**
	 * The stream context, set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * The open file's bytes: the fetched body when reading, the pending buffer when writing.
	 */
	private string $buffer = '';

	/**
	 * Read/write offset into $buffer.
	 */
	private int $position = 0;

	/**
	 * The uri currently open.
	 */
	private string $uri = '';

	/**
	 * Whether this handle may write.
	 */
	private bool $writable = false;

	/**
	 * Whether the buffer has changed and needs flushing.
	 */
	private bool $dirty = false;

	/**
	 * Directory listing state for `opendir()`/`readdir()`.
	 */
	private array $entries = [];

	/**
	 * Cursor into $entries.
	 */
	private int $entryAt = 0;

	/**
	 * Registers this wrapper for every scheme it owns, replacing anything present.
	 *
	 * @return array
	 *   The schemes actually registered.
	 */
	public static function register(array $schemes = self::SCHEMES): array
	{
		$registered = [];
		foreach ($schemes as $scheme) {
			if (in_array($scheme, stream_get_wrappers(), true)) {
				// a wrapper cannot be replaced without unregistering it first
				@stream_wrapper_unregister($scheme);
			}
			if (@stream_wrapper_register($scheme, static::class, 0)) {
				$registered[] = $scheme;
			}
		}
		return $registered;
	}

	/**
	 * Whether the host installed the file capability at all.
	 *
	 * Checked rather than assumed, because a Worker deployed without it must fail loudly at
	 * registration instead of accepting writes that go nowhere.
	 */
	public static function available(): bool
	{
		return Host::has('cfwFileWrite') && Host::has('cfwFileRead');
	}

	/**
	 * Opens a file for reading or writing.
	 */
	public function stream_open($path, $mode, $options, &$opened_path): bool
	{
		$this->uri = $path;
		$this->position = 0;
		$this->buffer = '';
		$this->dirty = false;

		$base = rtrim($mode, 'bt+');
		$plus = str_contains($mode, '+');
		$this->writable = $base !== 'r' || $plus;

		if ($base === 'r' || $plus || $base === 'a') {
			$reply = Host::call('cfwFileRead', ['uri' => $path]);
			$found = ($reply['ok'] ?? false) === true;
			if (!$found && $base === 'r') {
				// 'r' on an absent file is a failure; 'a' and 'w' create it
				return $this->fail($options, sprintf('%s does not exist', $path));
			}
			if ($found) {
				// an ABSENT b64 field is a malformed reply, not an empty file: defaulting it would
				// make a broken host indistinguishable from a genuinely empty upload
				if (!array_key_exists('b64', $reply)) {
					return $this->fail(
						$options,
						sprintf('read reply for %s carried no b64', $path),
					);
				}
				$this->buffer = (string) base64_decode((string) $reply['b64'], true);
			}
		}

		// 'a' appends, so the cursor starts at the end; 'w' truncates, which the empty buffer did
		if ($base === 'a') {
			$this->position = strlen($this->buffer);
		}
		if ($base === 'w') {
			$this->buffer = '';
			// a truncating open is itself a change, so an fopen('w') followed by fclose() with no
			// write must still produce an empty file rather than leaving the old bytes in place
			$this->dirty = true;
		}

		if ($opened_path !== null) {
			$opened_path = $path;
		}
		return true;
	}

	/**
	 * Serves bytes from the buffer.
	 */
	public function stream_read($count): string
	{
		$chunk = substr($this->buffer, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}

	/**
	 * Writes into the buffer, which `stream_flush()` commits.
	 *
	 * Returns 0 on a read-only handle, which is how PHP learns the write did not happen.
	 */
	public function stream_write($data): int
	{
		if (!$this->writable) {
			return 0;
		}
		// a write in the middle of a file replaces bytes rather than inserting them, which is what
		// fwrite() does on a real file; str_pad covers a write past the end after an fseek()
		if ($this->position > strlen($this->buffer)) {
			$this->buffer = str_pad($this->buffer, $this->position, "\0");
		}
		$this->buffer = substr_replace($this->buffer, $data, $this->position, strlen($data));
		$this->position += strlen($data);
		$this->dirty = true;
		return strlen($data);
	}

	/**
	 * Commits the buffer to durable storage.
	 *
	 * The return value is load-bearing: `fflush()` and `fclose()` both surface it, and reporting
	 * TRUE for a write that did not land is exactly the torn state this wrapper exists to avoid.
	 */
	public function stream_flush(): bool
	{
		if (!$this->dirty) {
			return true;
		}
		$reply = Host::call('cfwFileWrite', [
			'uri' => $this->uri,
			'b64' => base64_encode($this->buffer),
			'mime' => self::mimeFor($this->uri),
		]);
		$ok = ($reply['ok'] ?? false) === true;
		if ($ok) {
			$this->dirty = false;
		}
		return $ok;
	}

	/**
	 * Whether the read cursor has reached the end of the buffer.
	 *
	 * @return bool
	 *   TRUE once every byte has been read.
	 */
	public function stream_eof(): bool
	{
		return $this->position >= strlen($this->buffer);
	}

	/**
	 * Reports the current cursor position.
	 *
	 * @return int
	 *   Offset in bytes from the start of the file.
	 */
	public function stream_tell(): int
	{
		return $this->position;
	}

	/**
	 * Moves the cursor.
	 *
	 * A seek PAST the end is legal on a writable handle -- that is how a sparse write works -- and
	 * out of range on a read-only one.
	 */
	public function stream_seek($offset, $whence = SEEK_SET): bool
	{
		$target = match ($whence) {
			SEEK_CUR => $this->position + $offset,
			SEEK_END => strlen($this->buffer) + $offset,
			default => $offset,
		};
		if ($target < 0) {
			return false;
		}
		if ($target > strlen($this->buffer) && !$this->writable) {
			return false;
		}
		$this->position = $target;
		return true;
	}

	/**
	 * Truncates the open file, which `ftruncate()` needs.
	 */
	public function stream_truncate($new_size): bool
	{
		if (!$this->writable) {
			return false;
		}
		$size = max(0, $new_size);
		$this->buffer =
			$size <= strlen($this->buffer)
				? substr($this->buffer, 0, $size)
				: str_pad($this->buffer, $size, "\0");
		$this->dirty = true;
		return true;
	}

	/**
	 * Describes the open stream for `fstat()` and `filesize()`.
	 */
	public function stream_stat(): array
	{
		return self::statArray(strlen($this->buffer), 0);
	}

	/**
	 * Flushes and releases.
	 */
	public function stream_close(): void
	{
		// fclose() has no way to report a failed flush, so a refused write was lost here with the
		// file_managed row already committed -- an entity pointing at nothing, which is the exact
		// failure this class exists to prevent
		if (!$this->stream_flush()) {
			Degradation::record(
				'CfwFileStreamWrapper::stream_close',
				'a buffered write could not be committed to durable storage when the stream closed, so those bytes are lost while any entity referencing them was still saved',
			);
		}
		$this->buffer = '';
		$this->position = 0;
		$this->dirty = false;
	}

	/**
	 * Describes a path without opening it, for `file_exists()`, `filesize()` and `is_dir()`.
	 *
	 * A DIRECTORY is reported as one when any stored file sits under it. There are no directory
	 * records -- storage is a flat keyspace -- so a directory exists exactly when it has contents,
	 * which is the same rule an object store uses and is what `file_prepare_directory()` needs.
	 */
	public function url_stat($path, $flags): array|false
	{
		$reply = Host::call('cfwFileStat', ['uri' => $path]);
		if (($reply['ok'] ?? false) === true) {
			return self::statArray((int) ($reply['size'] ?? 0), (int) ($reply['modified'] ?? 0));
		}
		$prefix = rtrim($path, '/') . '/';
		$listing = Host::call('cfwFileList', ['prefix' => $prefix, 'limit' => 1]);
		$files = is_array($listing['files'] ?? null) ? $listing['files'] : [];
		if ($files !== []) {
			// 040755: a directory, which is what is_dir() reads
			return self::statArray(0, 0, 040755);
		}
		return false;
	}

	/**
	 * Deletes a file.
	 */
	public function unlink($path): bool
	{
		$reply = Host::call('cfwFileDelete', ['uri' => $path]);
		return ($reply['ok'] ?? false) === true;
	}

	/**
	 * Moves a file.
	 */
	public function rename($path_from, $path_to): bool
	{
		$reply = Host::call('cfwFileRename', [
			'from' => $path_from,
			'to' => $path_to,
			// PHP's rename() overwrites, so the host is told to as well; file_move()'s own replace
			// semantics are enforced above this layer by Drupal
			'overwrite' => true,
		]);
		return ($reply['ok'] ?? false) === true;
	}

	/**
	 * Creates a directory, which is a no-op that must still report success.
	 *
	 * Storage is a flat keyspace with no directory records, so there is nothing to create -- but
	 * `mkdir()` returning FALSE would make `file_prepare_directory()` refuse the whole write, and a
	 * directory that "exists" as soon as something is in it is the same model an object store uses.
	 */
	public function mkdir($path, $mode, $options): bool
	{
		return true;
	}

	/**
	 * Removes a directory by removing what is under it.
	 */
	public function rmdir($path, $options): bool
	{
		$prefix = rtrim($path, '/') . '/';
		$listing = Host::call('cfwFileList', ['prefix' => $prefix]);
		$files = is_array($listing['files'] ?? null) ? $listing['files'] : [];
		$failed = 0;
		foreach ($files as $file) {
			$uri = is_array($file) ? (string) ($file['uri'] ?? '') : '';
			if ($uri !== '') {
				$reply = Host::call('cfwFileDelete', ['uri' => $uri]);
				if (($reply['ok'] ?? false) !== true) {
					$failed++;
				}
			}
		}
		if ($failed > 0) {
			Degradation::record(
				'CfwFileStreamWrapper::rmdir',
				sprintf(
					'%d file(s) under a removed directory could not be deleted, so the directory reports gone while its contents are still stored and still billed',
					$failed,
				),
			);
		}
		return true;
	}

	/**
	 * Opens a directory listing.
	 */
	public function dir_opendir($path, $options): bool
	{
		$prefix = rtrim($path, '/') . '/';
		$listing = Host::call('cfwFileList', ['prefix' => $prefix]);
		$files = is_array($listing['files'] ?? null) ? $listing['files'] : [];
		$names = [];
		foreach ($files as $file) {
			$uri = is_array($file) ? (string) ($file['uri'] ?? '') : '';
			if ($uri === '' || !str_starts_with($uri, $prefix)) {
				continue;
			}
			$rest = substr($uri, strlen($prefix));
			// only the immediate child: a nested path contributes its directory name once, which is
			// what readdir() reports for a real directory
			$slash = strpos($rest, '/');
			$names[$slash === false ? $rest : substr($rest, 0, $slash)] = true;
		}
		$this->entries = array_keys($names);
		sort($this->entries);
		$this->entryAt = 0;
		return true;
	}

	/**
	 * Returns the next entry in the open directory listing.
	 *
	 * @return string|false
	 *   The entry name, or FALSE once the listing is exhausted.
	 */
	public function dir_readdir(): string|false
	{
		return $this->entries[$this->entryAt++] ?? false;
	}

	/**
	 * Restarts the directory listing from the first entry.
	 *
	 * @return bool
	 *   Always TRUE; the listing is held in memory so a rewind cannot fail.
	 */
	public function dir_rewinddir(): bool
	{
		$this->entryAt = 0;
		return true;
	}

	/**
	 * Releases the directory listing.
	 *
	 * @return bool
	 *   Always TRUE.
	 */
	public function dir_closedir(): bool
	{
		$this->entries = [];
		$this->entryAt = 0;
		return true;
	}

	/**
	 * Accepts the metadata calls PHP makes and reports them as unsupported.
	 *
	 * Declared with the REAL prototype -- `touch()`, `chmod()` and `chown()` invoke this with three
	 * arguments -- because a zero-argument method under this name would take an
	 * `ArgumentCountError` on the first caller. There are no permissions or mtimes to set on this
	 * storage, so FALSE is the honest answer rather than a silent TRUE.
	 */
	public function stream_metadata($path, $option, $value): bool
	{
		return false;
	}

	/**
	 * Reports no locking, because there is none to report.
	 */
	public function stream_lock($operation): bool
	{
		return false;
	}

	// #region Drupal's StreamWrapperInterface
	//
	// `public://` already
	// belongs to Drupal: `StreamWrapperManager` registers `PublicStream` for it during container
	// boot, so a bare `stream_wrapper_register('public', ...)` either loses the race or is undone
	// the next time the manager runs. The supported override is the `stream_wrapper` service tag,
	// which requires this interface -- and the tag is deliverable through
	// `sites/default/services.yml`, the one file whose path is already inside the container cache
	// key, so adding it does not invalidate the baked container.

	/**
	 * {@inheritdoc}
	 */
	public static function getType(): int
	{
		// LOCAL is deliberately NOT set. It promises `realpath()` returns a usable filesystem path,
		// and code that believes it will hand the value to a native file function -- which cannot
		// reach storage that lives in SQL.
		return StreamWrapperInterface::NORMAL;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string
	{
		return $this->scheme === 'private' ? 'Durable private files' : 'Durable public files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDescription(): string
	{
		return 'Files stored in the Durable Object, so they survive an eviction.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function setUri($uri): void
	{
		$this->uri = (string) $uri;
		$scheme = self::schemeOf($this->uri);
		if (in_array($scheme, self::SCHEMES, true)) {
			$this->scheme = $scheme;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getUri(): string
	{
		return $this->uri;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Keeps Drupal's own `/sites/default/files/<target>` shape rather than inventing a route, so
	 * every existing theme, image style and `file_url_generator` caller keeps working unchanged and
	 * the Worker serves that prefix out of the same store.
	 */
	public function getExternalUrl(): string
	{
		$base = $this->scheme === 'private' ? '/system/files/' : '/sites/default/files/';
		return $base . self::targetOf($this->uri);
	}

	/**
	 * Largest file this will materialise into MEMFS, in bytes.
	 *
	 * MEMFS is the same linear memory the interpreter runs in, and the shipping build peaks about
	 * 6.3 MiB under the isolate limit -- so materialising is spending the scarcest resource the
	 * runtime has. 2 MiB covers the images and documents `realpath()` is actually reached for and
	 * leaves the cap intact for the render itself.
	 */
	public const REALPATH_MAX_BYTES = 2097152;

	/**
	 * Where materialised bytes land.
	 *
	 * Under `/tmp` because emscripten's MEMFS always creates it -- the opcache abort that took a
	 * whole deploy to find was a path that did not exist yet at module-startup time.
	 */
	public const MATERIALISE_DIR = '/tmp/cfw-realpath';

	/**
	 * {@inheritdoc}
	 *
	 * MATERIALISES ON DEMAND, which reverses what this method used to do. Returning FALSE was
	 * defensible -- there is genuinely no path on any filesystem holding these bytes -- but it was
	 * a SILENT gap, which is the one outcome a compatibility layer may not produce. `strata_files`
	 * captured nothing for the same reason: `ManagedFileCapture` early-returns on a FALSE realpath, so a module
	 * that looked installed and tested captured no files and nothing said so.
	 *
	 * So the bytes are written into MEMFS under the real files path and that path is returned.
	 * `is_file()` and `Hash::ofStream(fopen($path))` then both work against an UNMODIFIED module,
	 * which is the whole product claim. The lazy-FS budget evicts what it writes.
	 *
	 * ABOVE THE THRESHOLD IT STILL RETURNS FALSE, but DECLARES rather than staying quiet -- the
	 * declared-degradation pattern in its first real use. The caller gets the same answer it used
	 * to get; the difference is that an operator can now see why on the status report.
	 *
	 * No PHP return type, and core is the reason: `StreamWrapperInterface` tags this
	 * `@return string` while the prose directly beneath says "or FALSE on failure or if the
	 * registered wrapper does not provide an implementation" -- and core's own `LocalStream`
	 * returns `getLocalPath()`, which is `string|false`. So the tag is wrong upstream. Declaring
	 * `: false` here made that contradiction PHPStan's problem instead of core's.
	 *
	 * @return string|false
	 *   A MEMFS path holding the bytes, or FALSE when they are too large or absent.
	 */
	public function realpath()
	{
		$uri = $this->uri;
		if ($uri === '') {
			return false;
		}

		$stat = $this->url_stat($uri, 0);
		if ($stat === false) {
			// absent is not a degradation; it is the correct answer for a file that is not there
			return false;
		}

		$size = (int) ($stat['size'] ?? 0);
		if ($size > self::REALPATH_MAX_BYTES) {
			Degradation::record(
				'CfwFileStreamWrapper::realpath',
				sprintf(
					'a file above %d bytes is not materialised into MEMFS, so native file functions cannot reach it; code that needs a local path will skip this file',
					self::REALPATH_MAX_BYTES,
				),
			);
			return false;
		}

		$local = self::MATERIALISE_DIR . '/' . md5($uri) . '-' . basename(self::targetOf($uri));
		// already materialised this boot, and the same uri always maps to the same path
		if (is_file($local) && filesize($local) === $size) {
			return $local;
		}

		// each arm below is the SAME observable outcome as the size refusal above -- a module that
		// needs a local path skips the file -- and only that one used to say so
		$give = static function (string $why): bool {
			Degradation::record('CfwFileStreamWrapper::realpath', $why);
			return false;
		};

		$reply = Host::call('cfwFileRead', ['uri' => $uri]);
		if (($reply['ok'] ?? false) !== true || !array_key_exists('b64', $reply)) {
			return $give(
				'a stored file could not be read back, so it cannot be materialised for code that needs a local path',
			);
		}
		$bytes = base64_decode((string) $reply['b64'], true);
		if ($bytes === false) {
			return $give(
				'a stored file came back as base64 that will not decode, which means the stored bytes are damaged',
			);
		}

		if (!is_dir(self::MATERIALISE_DIR) && !@mkdir(self::MATERIALISE_DIR, 0777, true)) {
			return $give(
				'the in-memory staging directory could not be created, so no file can be materialised at all',
			);
		}
		// a partial write would hand back a path to truncated bytes, which is worse than FALSE
		if (@file_put_contents($local, $bytes) !== strlen($bytes)) {
			@unlink($local);
			return $give(
				'a file was materialised only partly and was discarded rather than handed over truncated; the isolate is probably out of memory',
			);
		}
		return $local;
	}

	/**
	 * {@inheritdoc}
	 */
	public function dirname($uri = null): string
	{
		$target = (string) ($uri ?? $this->uri);
		$scheme = self::schemeOf($target) ?: $this->scheme;
		$path = self::targetOf($target);
		$parent = dirname($path);
		// dirname() answers '.' for a bare filename, which is not a uri Drupal can use
		return $scheme . '://' . ($parent === '.' || $parent === '' ? '' : $parent);
	}

	/**
	 * The URL-facing directory prefix for this scheme.
	 *
	 * NOT part of `StreamWrapperInterface`, and that is exactly why it is here. Core's image module
	 * calls it directly on whatever answers `getViaScheme('public')` --
	 * `ImageStyleRoutes.php:53` builds the `image.style_public` route out of the return value -- so
	 * `LocalStream`'s method is a de-facto part of the contract even though the interface never
	 * declares it. Omitting it does not degrade anything gracefully: enabling a module takes
	 * `Call to undefined method ... ::getDirectoryPath()` while REBUILDING THE ROUTER, which is the
	 * least recoverable moment in an install. Measured, not predicted.
	 *
	 * Returns the same strings core does, so the generated routes and every existing image-style
	 * URL are unchanged.
	 */
	public function getDirectoryPath(): string
	{
		return $this->scheme === 'private' ? 'system/files' : 'sites/default/files';
	}

	/**
	 * Required by PHP for `stream_select()`; there is no underlying descriptor to hand back.
	 */
	public function stream_cast($cast_as)
	{
		return false;
	}

	/**
	 * Accepts `stream_set_*` calls and reports them unsupported; there is no socket to configure.
	 */
	public function stream_set_option($option, $arg1, $arg2): bool
	{
		return false;
	}

	// #endregion

	/**
	 * A stat array in the shape PHP expects, with the numeric and named keys it duplicates.
	 */
	private static function statArray(int $size, int $mtimeMs, int $mode = 0100666): array
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
		// PHP's stat() exposes both the positional and the named form, and code reads either
		return array_merge(array_values($values), $values);
	}

	/**
	 * The part of a uri after `scheme://`.
	 *
	 * `parse_url()` CANNOT do this and using it was a bug caught by running the class rather than
	 * reading it: for `public://styles/thumb/a.png` it reports the host as `styles` and the path as
	 * `/thumb/a.png`, so the external URL silently lost a directory; for `private://secret.txt` the
	 * whole filename became the host and the path was empty. Drupal's own
	 * `StreamWrapperManager::getTarget()` splits on the delimiter for the same reason.
	 */
	private static function targetOf(string $uri): string
	{
		$parts = explode('://', $uri, 2);
		return ltrim($parts[1] ?? '', '/');
	}

	/**
	 * The scheme of a uri, or the empty string.
	 */
	private static function schemeOf(string $uri): string
	{
		$parts = explode('://', $uri, 2);
		return count($parts) === 2 ? strtolower($parts[0]) : '';
	}

	/**
	 * A content type from the extension, for the stored metadata.
	 *
	 * Deliberately a short list rather than a full map: the value is advisory metadata on the
	 * stored row, and Drupal has its own MIME guesser for anything user-facing.
	 */
	private static function mimeFor(string $uri): ?string
	{
		$ext = strtolower((string) pathinfo(self::targetOf($uri), PATHINFO_EXTENSION));
		return match ($ext) {
			'png' => 'image/png',
			'jpg', 'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'svg' => 'image/svg+xml',
			'pdf' => 'application/pdf',
			'css' => 'text/css',
			'js' => 'text/javascript',
			'json' => 'application/json',
			'txt' => 'text/plain',
			'html' => 'text/html',
			default => null,
		};
	}

	/**
	 * Emits a warning unless the caller asked for silence, and fails the open.
	 */
	private function fail(int $options, string $message): bool
	{
		if ($options & STREAM_REPORT_ERRORS) {
			trigger_error($message, E_USER_WARNING);
		}
		return false;
	}
}
