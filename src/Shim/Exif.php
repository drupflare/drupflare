<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

/**
 * The exif_read_data() function in PHP, for a build without ext-exif.
 *
 * Reads the TIFF structure inside a JPEG APP1 segment, or a bare TIFF file: IFD0 and the Exif
 * sub-IFD, with the FILE and COMPUTED sections ext-exif adds. The tag that matters most is
 * Orientation, which image styles with auto-orient read on every upload. Maker notes, GPS and
 * thumbnails are not decoded; their tags are absent rather than guessed.
 */
final class Exif
{
	/**
	 * The tag names ext-exif reports, for the tags this reader decodes.
	 */
	private const TAGS = [
		0x010e => 'ImageDescription',
		0x010f => 'Make',
		0x0110 => 'Model',
		0x0112 => 'Orientation',
		0x011a => 'XResolution',
		0x011b => 'YResolution',
		0x0128 => 'ResolutionUnit',
		0x0131 => 'Software',
		0x0132 => 'DateTime',
		0x013b => 'Artist',
		0x0213 => 'YCbCrPositioning',
		0x8298 => 'Copyright',
		0x8769 => 'Exif_IFD_Pointer',
		0x8825 => 'GPS_IFD_Pointer',
		0x829a => 'ExposureTime',
		0x829d => 'FNumber',
		0x8827 => 'ISOSpeedRatings',
		0x9000 => 'ExifVersion',
		0x9003 => 'DateTimeOriginal',
		0x9004 => 'DateTimeDigitized',
		0x920a => 'FocalLength',
		0xa001 => 'ColorSpace',
		0xa002 => 'ExifImageWidth',
		0xa003 => 'ExifImageLength',
	];

	/**
	 * Same arguments and return shape as ext-exif.
	 *
	 * @param mixed $file
	 *   A path (any stream wrapper) or an open stream.
	 * @param string|null $required_sections
	 *   Comma-separated sections that must be present, or null.
	 * @param bool $as_arrays
	 *   Whether sections come back nested.
	 * @param bool $read_thumbnail
	 *   Accepted and ignored; thumbnails are not decoded.
	 *
	 * @return array|false
	 *   The sections flattened (or nested when $as_arrays), or FALSE for something that is not a
	 *   JPEG or TIFF.
	 */
	public static function read(
		mixed $file,
		?string $required_sections = null,
		bool $as_arrays = false,
		bool $read_thumbnail = false,
	): array|false {
		if (is_resource($file)) {
			$bytes = stream_get_contents($file, -1, 0);
			$name = '';
		} else {
			$bytes = @file_get_contents((string) $file);
			$name = basename((string) $file);
		}
		if (!is_string($bytes) || $bytes === '') {
			trigger_error('exif_read_data(): Unable to open file', E_USER_WARNING);
			return false;
		}

		$tiff = null;
		$mime = '';
		if (str_starts_with($bytes, "\xff\xd8")) {
			$mime = 'image/jpeg';
			$tiff = self::app1($bytes);
		} elseif (str_starts_with($bytes, "II*\0") || str_starts_with($bytes, "MM\0*")) {
			$mime = 'image/tiff';
			$tiff = $bytes;
		} else {
			trigger_error('exif_read_data(): File not supported', E_USER_WARNING);
			return false;
		}

		$sections = [
			'FILE' => [
				'FileName' => $name,
				'FileDateTime' => 0,
				'FileSize' => strlen($bytes),
				'FileType' => $mime === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_TIFF_II,
				'MimeType' => $mime,
				'SectionsFound' => '',
			],
			'COMPUTED' => [],
		];
		$ifd0 = [];
		$exif = [];
		if ($tiff !== null) {
			[$ifd0, $exif] = self::tiff($tiff);
		}
		$found = [];
		if ($ifd0 !== []) {
			$found[] = 'ANY_TAG';
			$found[] = 'IFD0';
			$sections['IFD0'] = $ifd0;
		}
		if ($exif !== []) {
			$found[] = 'EXIF';
			$sections['EXIF'] = $exif;
		}
		$sections['FILE']['SectionsFound'] = implode(', ', $found);

		$size = @getimagesizefromstring($bytes);
		if (is_array($size)) {
			$sections['COMPUTED'] = [
				'html' => sprintf('width="%d" height="%d"', $size[0], $size[1]),
				'Height' => $size[1],
				'Width' => $size[0],
				'IsColor' => ($size['channels'] ?? 3) >= 3 ? 1 : 0,
			];
		}

		if ($required_sections !== null && $required_sections !== '') {
			foreach (
				preg_split('/[\s,]+/', strtoupper($required_sections), -1, PREG_SPLIT_NO_EMPTY) ?:
				[]
				as $want
			) {
				if ($want === 'ANY_TAG' ? $found === [] : !isset($sections[$want])) {
					return false;
				}
			}
		}

		if ($as_arrays) {
			return $sections;
		}
		$flat = [];
		foreach ($sections as $section) {
			$flat += $section;
		}
		return $flat;
	}

	/**
	 * The TIFF payload of the first Exif APP1 segment, or NULL.
	 */
	private static function app1(string $jpeg): ?string
	{
		$pos = 2;
		$length = strlen($jpeg);
		while ($pos + 4 <= $length && $jpeg[$pos] === "\xff") {
			$marker = ord($jpeg[$pos + 1]);
			if ($marker === 0xd9 || $marker === 0xda) {
				break;
			}
			$size = unpack('n', substr($jpeg, $pos + 2, 2))[1] ?? 0;
			if ($marker === 0xe1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
				return substr($jpeg, $pos + 10, $size - 8);
			}
			$pos += 2 + $size;
		}
		return null;
	}

	/**
	 * IFD0 and the Exif sub-IFD of a TIFF structure.
	 *
	 * @param string $tiff
	 *   The TIFF structure.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 *   IFD0 tags, then Exif sub-IFD tags.
	 */
	private static function tiff(string $tiff): array
	{
		$little = str_starts_with($tiff, 'II');
		$u16 = static fn(int $at): int => (int) (unpack(
			$little ? 'v' : 'n',
			substr($tiff, $at, 2),
		)[1] ?? 0);
		$u32 = static fn(int $at): int => (int) (unpack(
			$little ? 'V' : 'N',
			substr($tiff, $at, 4),
		)[1] ?? 0);
		$ifd0 = self::ifd($tiff, $u32(4), $u16, $u32);
		$exif = [];
		if (isset($ifd0['Exif_IFD_Pointer'])) {
			$exif = self::ifd($tiff, (int) $ifd0['Exif_IFD_Pointer'], $u16, $u32);
		}
		return [$ifd0, $exif];
	}

	/**
	 * One IFD's known tags, decoded to the types ext-exif returns.
	 *
	 * @param string $tiff
	 *   The TIFF structure.
	 * @param int $offset
	 *   Where the IFD starts.
	 * @param callable $u16
	 *   Reads an unsigned 16-bit value at an offset.
	 * @param callable $u32
	 *   Reads an unsigned 32-bit value at an offset.
	 *
	 * @return array<string, mixed>
	 *   The decoded tags by name.
	 */
	private static function ifd(string $tiff, int $offset, callable $u16, callable $u32): array
	{
		$out = [];
		if ($offset <= 0 || $offset + 2 > strlen($tiff)) {
			return $out;
		}
		$count = $u16($offset);
		for ($i = 0; $i < $count; $i++) {
			$at = $offset + 2 + $i * 12;
			if ($at + 12 > strlen($tiff)) {
				break;
			}
			$tag = $u16($at);
			if (!isset(self::TAGS[$tag])) {
				continue;
			}
			$type = $u16($at + 2);
			$n = $u32($at + 4);
			$width = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8][$type] ?? 0;
			if ($width === 0) {
				continue;
			}
			$bytes = $width * $n;
			$data = $bytes <= 4 ? $at + 8 : $u32($at + 8);
			$out[self::TAGS[$tag]] = match ($type) {
				2 => rtrim(substr($tiff, $data, $n), "\0"),
				3 => $n === 1
					? $u16($data)
					: self::many($n, static fn(int $k): int => $u16($data + $k * 2)),
				4, 9 => $n === 1
					? $u32($data)
					: self::many($n, static fn(int $k): int => $u32($data + $k * 4)),
				5, 10 => $n === 1
					? $u32($data) . '/' . $u32($data + 4)
					: self::many(
						$n,
						static fn(int $k): string => $u32($data + $k * 8) .
							'/' .
							$u32($data + $k * 8 + 4),
					),
				default => substr($tiff, $data, $n),
			};
		}
		return $out;
	}

	/**
	 * Reads a run of values.
	 *
	 * @param int $n
	 *   How many values.
	 * @param callable $at
	 *   Reads the value at an index.
	 *
	 * @return list<mixed>
	 *   The values.
	 */
	private static function many(int $n, callable $at): array
	{
		$out = [];
		for ($k = 0; $k < $n; $k++) {
			$out[] = $at($k);
		}
		return $out;
	}
}
