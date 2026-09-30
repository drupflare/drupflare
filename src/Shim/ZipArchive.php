<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use Countable;

/**
 * The ZipArchive class in PHP, over ext-zlib, for a build without ext-zip.
 *
 * The runtime aliases the global name to this class on first use, so `new \ZipArchive()` and a
 * subclass of it both resolve here. Exports (webform, node export, content sync), minisite uploads
 * and phpspreadsheet's xlsx reader and writer use the surface below: open, add, read by name or
 * index, stat, extract and close. Entries are stored or deflated; ZIP64, encryption and split
 * archives are refused by name, because a silently truncated archive reads as a smaller one.
 *
 * The archive lives in memory between open() and close(), and close() writes it back through
 * whatever stream wrapper the path names, so `temporary://` and `private://` work unchanged.
 */
class ZipArchive implements Countable
{
	public const CREATE = 1;
	public const EXCL = 2;
	public const CHECKCONS = 4;
	public const OVERWRITE = 8;
	public const RDONLY = 16;

	public const FL_NOCASE = 1;
	public const FL_NODIR = 2;
	public const FL_COMPRESSED = 4;
	public const FL_UNCHANGED = 8;

	public const CM_DEFAULT = -1;
	public const CM_STORE = 0;
	public const CM_DEFLATE = 8;

	public const ER_OK = 0;
	public const ER_READ = 5;
	public const ER_WRITE = 6;
	public const ER_CRC = 7;
	public const ER_NOENT = 9;
	public const ER_EXISTS = 10;
	public const ER_OPEN = 11;
	public const ER_MEMORY = 14;
	public const ER_COMPNOTSUPP = 16;
	public const ER_INVAL = 18;
	public const ER_NOZIP = 19;
	public const ER_INCONS = 21;

	public int $numFiles = 0;
	public int $status = self::ER_OK;
	public int $statusSys = 0;
	public string $filename = '';
	public string $comment = '';

	/**
	 * Entries in archive order: name, data (uncompressed), mtime, method, and a deleted flag.
	 *
	 * @var array<int, array{name: string, data: string|null, raw: string|null, crc: int, size: int, csize: int, method: int, mtime: int, deleted: bool}>
	 */
	private array $entries = [];

	private bool $open = false;
	private bool $dirty = false;
	private bool $readOnly = false;

	/**
	 * Opens or creates an archive.
	 *
	 * @return bool|int
	 *   TRUE, or an ER_* code.
	 */
	public function open(string $filename, int $flags = 0): bool|int
	{
		$this->reset();
		$exists = @file_exists($filename) && @filesize($filename) > 0;
		if ($exists && $flags & self::EXCL) {
			return self::ER_EXISTS;
		}
		if (!$exists && !($flags & (self::CREATE | self::OVERWRITE))) {
			return self::ER_NOENT;
		}
		$this->filename = $filename;
		$this->readOnly = (bool) ($flags & self::RDONLY);
		if ($exists && !($flags & self::OVERWRITE)) {
			$bytes = @file_get_contents($filename);
			if ($bytes === false) {
				return self::ER_READ;
			}
			$code = $this->parse($bytes);
			if ($code !== self::ER_OK) {
				$this->reset();
				return $code;
			}
		}
		if ($flags & self::OVERWRITE) {
			$this->dirty = true;
		}
		$this->open = true;
		$this->refresh();
		return true;
	}

	public function close(): bool
	{
		if (!$this->open) {
			return false;
		}
		$ok = true;
		if ($this->dirty && !$this->readOnly) {
			$live = array_filter($this->entries, static fn(array $e): bool => !$e['deleted']);
			// libzip writes nothing for an archive left empty, and removes one that existed
			if ($live === []) {
				if (@file_exists($this->filename)) {
					@unlink($this->filename);
				}
			} else {
				$ok = @file_put_contents($this->filename, $this->serialize($live)) !== false;
			}
		}
		$this->reset();
		return $ok;
	}

	public function count(): int
	{
		return $this->numFiles;
	}

	public function addFromString(string $name, string $content, int $flags = 0): bool
	{
		if (!$this->writable() || $name === '') {
			return false;
		}
		$this->put($name, $content);
		return true;
	}

	public function addFile(
		string $filepath,
		string $entryname = '',
		int $start = 0,
		int $length = 0,
		int $flags = 0,
	): bool {
		if (!$this->writable()) {
			return false;
		}
		$content = @file_get_contents($filepath);
		if ($content === false) {
			$this->status = self::ER_OPEN;
			return false;
		}
		if ($start > 0 || $length > 0) {
			$content = substr($content, $start, $length > 0 ? $length : null);
		}
		$this->put($entryname !== '' ? $entryname : ltrim($filepath, '/'), $content);
		return true;
	}

	public function addEmptyDir(string $dirname, int $flags = 0): bool
	{
		if (!$this->writable()) {
			return false;
		}
		$this->put(rtrim($dirname, '/') . '/', '');
		return true;
	}

	public function locateName(string $name, int $flags = 0): int|false
	{
		foreach ($this->entries as $index => $entry) {
			if ($entry['deleted']) {
				continue;
			}
			$candidate = $entry['name'];
			$wanted = $name;
			if ($flags & self::FL_NODIR) {
				$candidate = basename($candidate);
			}
			if (
				$flags & self::FL_NOCASE
					? strcasecmp($candidate, $wanted) === 0
					: $candidate === $wanted
			) {
				return $index;
			}
		}
		return false;
	}

	public function getNameIndex(int $index, int $flags = 0): string|false
	{
		$entry = $this->entry($index);
		return $entry === null ? false : $entry['name'];
	}

	public function getFromName(string $name, int $len = 0, int $flags = 0): string|false
	{
		$index = $this->locateName($name, $flags);
		return $index === false ? false : $this->getFromIndex($index, $len, $flags);
	}

	public function getFromIndex(int $index, int $len = 0, int $flags = 0): string|false
	{
		$data = $this->data($index);
		if ($data === null) {
			return false;
		}
		return $len > 0 ? substr($data, 0, $len) : $data;
	}

	/**
	 * Reports one entry's name, index, crc, size, mtime and compression.
	 *
	 * @param int $index
	 *   The entry index.
	 * @param int $flags
	 *   Accepted for compatibility.
	 *
	 * @return array|false
	 *   The keys name, index, crc, size, mtime, comp_size, comp_method and encryption_method.
	 */
	public function statIndex(int $index, int $flags = 0): array|false
	{
		$entry = $this->entry($index);
		if ($entry === null) {
			return false;
		}
		return [
			'name' => $entry['name'],
			'index' => $index,
			'crc' => $entry['crc'],
			'size' => $entry['size'],
			'mtime' => $entry['mtime'],
			'comp_size' => $entry['csize'],
			'comp_method' => $entry['method'],
			'encryption_method' => 0,
		];
	}

	public function statName(string $name, int $flags = 0): array|false
	{
		$index = $this->locateName($name, $flags);
		return $index === false ? false : $this->statIndex($index, $flags);
	}

	public function deleteIndex(int $index): bool
	{
		if (!$this->writable() || $this->entry($index) === null) {
			return false;
		}
		$this->entries[$index]['deleted'] = true;
		$this->dirty = true;
		$this->refresh();
		return true;
	}

	public function deleteName(string $name): bool
	{
		$index = $this->locateName($name);
		return $index !== false && $this->deleteIndex($index);
	}

	public function renameName(string $name, string $new_name): bool
	{
		$index = $this->locateName($name);
		if ($index === false || !$this->writable() || $this->locateName($new_name) !== false) {
			return false;
		}
		$this->entries[$index]['name'] = $new_name;
		$this->dirty = true;
		return true;
	}

	public function setArchiveComment(string $comment): bool
	{
		if (!$this->writable()) {
			return false;
		}
		$this->comment = $comment;
		$this->dirty = true;
		return true;
	}

	public function getArchiveComment(int $flags = 0): string|false
	{
		return $this->open ? $this->comment : false;
	}

	public function setCompressionName(string $name, int $method, int $compflags = 0): bool
	{
		$index = $this->locateName($name);
		if (
			$index === false ||
			!in_array($method, [self::CM_DEFAULT, self::CM_STORE, self::CM_DEFLATE], true)
		) {
			return false;
		}
		$this->data($index);
		$this->entries[$index]['method'] =
			$method === self::CM_STORE ? self::CM_STORE : self::CM_DEFLATE;
		$this->entries[$index]['raw'] = null;
		$this->dirty = true;
		return true;
	}

	/**
	 * Extracts every entry, or the named ones, under a directory.
	 *
	 * @param string $pathto
	 *   The directory to extract under.
	 * @param string|string[]|null $files
	 *   The entry names to extract, or null for all.
	 *
	 * @return bool
	 *   True when every entry was written.
	 */
	public function extractTo(string $pathto, array|string|null $files = null): bool
	{
		if (!$this->open) {
			return false;
		}
		$wanted = $files === null ? null : (array) $files;
		foreach ($this->entries as $index => $entry) {
			if (
				$entry['deleted'] ||
				($wanted !== null && !in_array($entry['name'], $wanted, true))
			) {
				continue;
			}
			// a name climbing out of the target is refused, which is the zip-slip rule libzip keeps
			$parts = array_filter(
				explode('/', str_replace('\\', '/', $entry['name'])),
				static fn(string $part): bool => $part !== '',
			);
			if (in_array('..', $parts, true)) {
				return false;
			}
			$target = rtrim($pathto, '/') . '/' . implode('/', $parts);
			if (str_ends_with($entry['name'], '/')) {
				if (!is_dir($target) && !@mkdir($target, 0777, true)) {
					return false;
				}
				continue;
			}
			$dir = dirname($target);
			if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
				return false;
			}
			$data = $this->data($index);
			if ($data === null || @file_put_contents($target, $data) === false) {
				return false;
			}
		}
		return true;
	}

	public function getStatusString(): string
	{
		return match ($this->status) {
			self::ER_OK => 'No error',
			self::ER_NOENT => 'No such file',
			self::ER_EXISTS => 'File already exists',
			self::ER_OPEN => "Can't open file",
			self::ER_READ => 'Read error',
			self::ER_NOZIP => 'Not a zip archive',
			self::ER_INCONS => 'Zip archive inconsistent',
			self::ER_COMPNOTSUPP => 'Compression method not supported',
			default => 'Error ' . $this->status,
		};
	}

	/**
	 * Whether a compression method is supported, as ext-zip reports it.
	 */
	public static function isCompressionMethodSupported(int $method, bool $enc = true): bool
	{
		return in_array($method, [self::CM_STORE, self::CM_DEFLATE, self::CM_DEFAULT], true);
	}

	// #region internals

	/**
	 * Forgets the open archive.
	 */
	private function reset(): void
	{
		$this->entries = [];
		$this->open = false;
		$this->dirty = false;
		$this->readOnly = false;
		$this->numFiles = 0;
		$this->status = self::ER_OK;
		$this->filename = '';
		$this->comment = '';
	}

	private function refresh(): void
	{
		$this->numFiles = count(
			array_filter($this->entries, static fn(array $e): bool => !$e['deleted']),
		);
	}

	private function writable(): bool
	{
		if (!$this->open || $this->readOnly) {
			$this->status = self::ER_INVAL;
			return false;
		}
		return true;
	}

	private function put(string $name, string $content): void
	{
		$entry = [
			'name' => $name,
			'data' => $content,
			'raw' => null,
			'crc' => crc32($content),
			'size' => strlen($content),
			'csize' => strlen($content),
			'method' => str_ends_with($name, '/') ? self::CM_STORE : self::CM_DEFLATE,
			'mtime' => time(),
			'deleted' => false,
		];
		$index = $this->locateName($name);
		if ($index === false) {
			$this->entries[] = $entry;
		} else {
			$this->entries[$index] = $entry;
		}
		$this->dirty = true;
		$this->refresh();
	}

	/**
	 * Reads one live entry.
	 *
	 * @param int $index
	 *   The entry index.
	 *
	 * @return array{name: string, data: string|null, raw: string|null, crc: int, size: int, csize: int, method: int, mtime: int, deleted: bool}|null
	 *   The entry, or null when it is absent or deleted.
	 */
	private function entry(int $index): ?array
	{
		$entry = $this->entries[$index] ?? null;
		return $entry === null || $entry['deleted'] ? null : $entry;
	}

	private function data(int $index): ?string
	{
		$entry = $this->entry($index);
		if ($entry === null) {
			return null;
		}
		if ($entry['data'] !== null) {
			return $entry['data'];
		}
		$raw = (string) $entry['raw'];
		$data = match ($entry['method']) {
			self::CM_STORE => $raw,
			self::CM_DEFLATE => @gzinflate($raw),
			default => false,
		};
		if ($data === false) {
			$this->status =
				$entry['method'] === self::CM_STORE || $entry['method'] === self::CM_DEFLATE
					? self::ER_INCONS
					: self::ER_COMPNOTSUPP;
			return null;
		}
		if (crc32($data) !== $entry['crc']) {
			$this->status = self::ER_CRC;
			return null;
		}
		$this->entries[$index]['data'] = $data;
		return $data;
	}

	private function parse(string $bytes): int
	{
		$eocd = strrpos($bytes, "PK\x05\x06");
		if ($eocd === false || strlen($bytes) - $eocd < 22) {
			return self::ER_NOZIP;
		}
		$end = unpack(
			'vdisk/vcdisk/ventries/vtotal/Vsize/Voffset/vclen',
			substr($bytes, $eocd + 4, 18),
		);
		if ($end === false) {
			return self::ER_NOZIP;
		}
		if ($end['disk'] !== 0 || $end['total'] === 0xffff || $end['offset'] === 0xffffffff) {
			// split archives and ZIP64 are refused rather than half-read
			return self::ER_INCONS;
		}
		$this->comment = substr($bytes, $eocd + 22, $end['clen']);
		$pos = $end['offset'];
		for ($i = 0; $i < $end['total']; $i++) {
			if (substr($bytes, $pos, 4) !== "PK\x01\x02") {
				return self::ER_INCONS;
			}
			$h = unpack(
				'vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Vlocal',
				substr($bytes, $pos + 4, 42),
			);
			if ($h === false) {
				return self::ER_INCONS;
			}
			if ($h['flags'] & 1) {
				// encrypted entries have no key here
				return self::ER_COMPNOTSUPP;
			}
			$name = substr($bytes, $pos + 46, $h['nlen']);
			$local = $h['local'];
			if (substr($bytes, $local, 4) !== "PK\x03\x04") {
				return self::ER_INCONS;
			}
			$l = unpack('vnlen/velen', substr($bytes, $local + 26, 4));
			if ($l === false) {
				return self::ER_INCONS;
			}
			$this->entries[] = [
				'name' => $name,
				'data' => null,
				'raw' => substr($bytes, $local + 30 + $l['nlen'] + $l['elen'], $h['csize']),
				'crc' => $h['crc'],
				'size' => $h['size'],
				'csize' => $h['csize'],
				'method' => $h['method'],
				'mtime' => self::fromDos($h['date'], $h['time']),
				'deleted' => false,
			];
			$pos += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
		}
		return self::ER_OK;
	}

	/**
	 * Writes the entries out as a zip archive.
	 *
	 * @param array<int, array{name: string, data: string|null, raw: string|null, crc: int, size: int, csize: int, method: int, mtime: int, deleted: bool}> $entries
	 *   The live entries.
	 *
	 * @return string
	 *   The archive bytes.
	 */
	private function serialize(array $entries): string
	{
		$out = '';
		$central = '';
		$count = 0;
		foreach ($entries as $index => $entry) {
			if ($entry['raw'] !== null && $entry['data'] === null) {
				$raw = $entry['raw'];
			} else {
				$data = (string) $this->data($index);
				$raw = $entry['method'] === self::CM_DEFLATE ? (string) gzdeflate($data) : $data;
			}
			[$date, $time] = self::toDos($entry['mtime']);
			// general purpose bit 11: the name is UTF-8
			$fields = pack(
				'vvvvvVVVvv',
				20,
				0x0800,
				$entry['method'],
				$time,
				$date,
				$entry['crc'],
				strlen($raw),
				$entry['size'],
				strlen($entry['name']),
				0,
			);
			$offset = strlen($out);
			$out .= "PK\x03\x04" . $fields . $entry['name'] . $raw;
			$central .=
				"PK\x01\x02" .
				pack('v', 0x0314) .
				$fields .
				pack(
					'vvvVV',
					0,
					0,
					0,
					str_ends_with($entry['name'], '/') ? 0x41ed0010 : 0x81a40000,
					$offset,
				) .
				$entry['name'];
			$count++;
		}
		return $out .
			$central .
			"PK\x05\x06" .
			pack(
				'vvvvVVv',
				0,
				0,
				$count,
				$count,
				strlen($central),
				strlen($out),
				strlen($this->comment),
			) .
			$this->comment;
	}

	private static function fromDos(int $date, int $time): int
	{
		$made = mktime(
			($time >> 11) & 0x1f,
			($time >> 5) & 0x3f,
			($time & 0x1f) * 2,
			($date >> 5) & 0x0f,
			$date & 0x1f,
			(($date >> 9) & 0x7f) + 1980,
		);
		return $made === false ? 0 : $made;
	}

	/**
	 * Converts a timestamp to the DOS date and time zip stores.
	 *
	 * @param int $timestamp
	 *   Seconds since the epoch.
	 *
	 * @return array{0: int, 1: int}
	 *   DOS date and time.
	 */
	private static function toDos(int $timestamp): array
	{
		$t = getdate(max($timestamp, 315532800));
		return [
			(($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'],
			($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2),
		];
	}

	// #endregion
}
