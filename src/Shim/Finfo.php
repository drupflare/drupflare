<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use Drupal\drupflare\Degradation;

/**
 * The finfo class in PHP, by magic bytes, for a build without ext-fileinfo.
 *
 * Upload validators and flysystem's MIME detector ask one question: what type is this content.
 * libmagic answers it from a database of thousands of signatures; this class carries the ones a
 * CMS upload meets (images, PDF, office formats, archives, audio, video, text) and answers
 * `application/octet-stream` for anything else, which is libmagic's own answer for unknown bytes.
 * Office Open XML is told apart from a plain zip by its `[Content_Types].xml` member and top-level
 * directory, the way libmagic does it.
 */
class Finfo
{
	public const NONE = 0;
	public const MIME_TYPE = 16;
	public const MIME_ENCODING = 1024;
	public const MIME = 1040;
	public const EXTENSION = 16777216;

	private int $flags;

	public function __construct(int $flags = self::NONE, ?string $magic_database = null)
	{
		$this->flags = $flags;
		if ($magic_database !== null && $magic_database !== '') {
			Degradation::record(
				'finfo magic database',
				'a custom libmagic database is not read; the built-in signature table answers',
				'untested',
			);
		}
	}

	public function set_flags(int $flags): bool
	{
		$this->flags = $flags;
		return true;
	}

	public function file(
		string $filename,
		int $flags = self::NONE,
		mixed $context = null,
	): string|false {
		if (is_dir($filename)) {
			return $this->answer('directory', 'binary', $flags);
		}
		$handle = @fopen($filename, 'rb');
		if ($handle === false) {
			return false;
		}
		// the zip central directory is at the end, so a large file is read whole only when it is a zip
		$head = (string) fread($handle, 65536);
		if (str_starts_with($head, "PK\x03\x04") && !feof($handle)) {
			$head .= (string) stream_get_contents($handle);
		}
		fclose($handle);
		return $this->buffer($head, $flags);
	}

	public function buffer(
		string $string,
		int $flags = self::NONE,
		mixed $context = null,
	): string|false {
		[$type, $encoding] = self::detect($string);
		return $this->answer($type, $encoding, $flags);
	}

	private function answer(string $type, string $encoding, int $flags): string
	{
		$flags = $flags === self::NONE ? $this->flags : $flags;
		if (($flags & self::MIME) === self::MIME) {
			return $type . '; charset=' . $encoding;
		}
		if ($flags & self::MIME_ENCODING) {
			return $encoding;
		}
		return $type;
	}

	/**
	 * The MIME type and charset libmagic would report.
	 *
	 * @param string $bytes
	 *   The content to identify.
	 *
	 * @return array{0: string, 1: string}
	 *   The MIME type and the charset.
	 */
	public static function detect(string $bytes): array
	{
		if ($bytes === '') {
			return ['application/x-empty', 'binary'];
		}
		$at = static fn(int $offset, string $magic): bool => substr(
			$bytes,
			$offset,
			strlen($magic),
		) === $magic;
		$binary = match (true) {
			$at(0, "\xff\xd8\xff") => 'image/jpeg',
			$at(0, "\x89PNG\r\n\x1a\n") => 'image/png',
			$at(0, 'GIF87a'), $at(0, 'GIF89a') => 'image/gif',
			$at(0, 'RIFF') && $at(8, 'WEBP') => 'image/webp',
			$at(0, 'RIFF') && $at(8, 'WAVE') => 'audio/x-wav',
			$at(0, 'RIFF') && $at(8, 'AVI ') => 'video/x-msvideo',
			$at(0, 'BM') && strlen($bytes) > 14 => 'image/bmp',
			$at(0, "\0\0\1\0") => 'image/vnd.microsoft.icon',
			$at(0, "II*\0"), $at(0, "MM\0*") => 'image/tiff',
			$at(4, 'ftypavif') => 'image/avif',
			$at(4, 'ftypheic'), $at(4, 'ftypheix'), $at(4, 'ftypmif1') => 'image/heic',
			$at(4, 'ftypqt') => 'video/quicktime',
			$at(4, 'ftyp') => 'video/mp4',
			$at(0, "\x1aE\xdf\xa3") => 'video/webm',
			$at(0, 'OggS') => 'audio/ogg',
			$at(0, 'fLaC') => 'audio/flac',
			$at(0, 'ID3'),
			$at(0, "\xff\xfb"),
			$at(0, "\xff\xf3"),
			$at(0, "\xff\xf2")
				=> 'audio/mpeg',
			$at(0, '%PDF-') => 'application/pdf',
			$at(0, "\x1f\x8b") => 'application/gzip',
			$at(0, 'BZh') => 'application/x-bzip2',
			$at(0, "\xfd7zXZ\0") => 'application/x-xz',
			$at(0, "7z\xbc\xaf\x27\x1c") => 'application/x-7z-compressed',
			$at(0, "Rar!\x1a\x07") => 'application/x-rar',
			$at(0, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1") => 'application/vnd.ms-office',
			$at(0, "\0asm") => 'application/wasm',
			$at(0, 'wOFF') => 'font/woff',
			$at(0, 'wOF2') => 'font/woff2',
			$at(0, "\0\1\0\0\0"), $at(0, 'OTTO') => 'font/sfnt',
			$at(0, 'PK') && ($at(2, "\x03\x04") || $at(2, "\x05\x06")) => self::zipKind($bytes),
			default => null,
		};
		if ($binary !== null) {
			return [$binary, 'binary'];
		}
		if (str_contains($bytes, "\0") || preg_match('//u', $bytes) !== 1) {
			return ['application/octet-stream', 'binary'];
		}
		$charset = preg_match('/[\x80-\xff]/', $bytes) === 1 ? 'utf-8' : 'us-ascii';
		$text = ltrim(substr($bytes, 0, 4096), "\xef\xbb\xbf \t\r\n");
		$type = match (true) {
			str_starts_with($text, '{\\rtf') => 'text/rtf',
			preg_match('/^<\?xml\b[^>]*>\s*(?:<!--.*?-->\s*)*<svg\b/is', $text) === 1,
			str_starts_with($text, '<svg')
				=> 'image/svg+xml',
			str_starts_with($text, '<?xml') => 'text/xml',
			preg_match('/^(?:<!--.*?-->\s*)*<(?:!doctype\s+html|html|head|body)\b/is', $text) === 1
				=> 'text/html',
			str_starts_with($text, '%!PS') => 'application/postscript',
			str_starts_with($text, '<?php') => 'text/x-php',
			default => 'text/plain',
		};
		return [$type, $charset];
	}

	/**
	 * A zip, or the office format stored in one.
	 */
	private static function zipKind(string $bytes): string
	{
		if (str_contains($bytes, 'mimetypeapplication/vnd.oasis.opendocument.text')) {
			return 'application/vnd.oasis.opendocument.text';
		}
		if (str_contains($bytes, 'mimetypeapplication/vnd.oasis.opendocument.spreadsheet')) {
			return 'application/vnd.oasis.opendocument.spreadsheet';
		}
		if (str_contains($bytes, '[Content_Types].xml')) {
			foreach (
				[
					'word/' =>
						'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'xl/' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
					'ppt/' =>
						'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				]
				as $dir => $type
			) {
				if (str_contains($bytes, $dir)) {
					return $type;
				}
			}
		}
		return 'application/zip';
	}
}
