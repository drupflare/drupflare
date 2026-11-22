<?php

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;
use Drupal\drupflare\Http\Park;

const DRUPFLARE_IMAGE_SCHEME = 'cfwpark+image://';
const DRUPFLARE_IMAGE_DEFAULT_MAX_BYTES = 10485760;

/** the mime types the host encodes and decodes, with the format name it takes for each */
const DRUPFLARE_IMAGE_FORMATS = [
	'image/jpeg' => 'jpeg',
	'image/png' => 'png',
	'image/gif' => 'gif',
	'image/webp' => 'webp',
	'image/avif' => 'avif',
];

/**
 * What this request has asked the image host to do.
 *
 * @return array{requests: int, refused: int}
 */
function &drupflare_image_state(): array
{
	static $state = ['requests' => 0, 'refused' => 0];
	return $state;
}

function drupflare_image_max_bytes(): int
{
	return defined('DRUPFLARE_IMAGE_MAX_BYTES')
		? max(1, (int) DRUPFLARE_IMAGE_MAX_BYTES)
		: DRUPFLARE_IMAGE_DEFAULT_MAX_BYTES;
}

/** whether the host answers image requests, which is the only engine there is */
function drupflare_image_available(): bool
{
	return Park::available() && Host::flag('cfwParkImage');
}

/**
 * Applies a queue of operations to an encoded image and encodes the result.
 *
 * @param array<int, array<string, mixed>> $ops
 *
 * @return array{bytes: string, width: int, height: int}|string
 *   The encoded image, or the reason there is none.
 */
function drupflare_image_render(
	string $source,
	array $ops,
	string $format,
	int $quality,
	bool $progressive = false,
): array|string {
	$state = &drupflare_image_state();
	$request = json_encode([
		'source' => base64_encode($source),
		'canvas' => null,
		'ops' => $ops,
		'format' => $format,
		'quality' => $quality,
		'progressive' => $progressive,
	]);
	if ($request === false) {
		return 'the request could not be encoded';
	}
	$state['requests']++;
	$raw = Park::yieldTo(DRUPFLARE_IMAGE_SCHEME . base64_encode($request));
	if (!is_string($raw)) {
		$state['refused']++;
		$frames = Park::refusal()['frames'];
		return 'the call could not be suspended for the host' .
			($frames === [] ? '' : ' under ' . implode(', ', $frames));
	}
	$reply = json_decode($raw, true);
	if (!is_array($reply)) {
		return 'the host answered something that is not JSON';
	}
	if (isset($reply['error'])) {
		return (string) $reply['error'];
	}
	$bytes = base64_decode((string) ($reply['bytes'] ?? ''), true);
	if ($bytes === false || $bytes === '') {
		return 'the host answered no image bytes';
	}
	return [
		'bytes' => $bytes,
		'width' => (int) ($reply['width'] ?? 0),
		'height' => (int) ($reply['height'] ?? 0),
	];
}

/**
 * The only editor WordPress is offered: no GD, which the stand-in answers for without an engine
 * behind every call, and no Imagick, which does not exist here.
 *
 * @param array<int, string> $editors
 *
 * @return array<int, string>
 */
function drupflare_image_editors($editors)
{
	require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
	require_once __DIR__ . '/image-editor.php';
	return ['Drupflare_Image_Editor'];
}

function drupflare_image_note_progressive(string $mime): void
{
	if ($mime !== 'image/jpeg') {
		Degradation::record(
			'wordpress image progressive',
			'the host writes only a JPEG progressive, so a PNG or GIF is written without interlacing',
			'blocked',
		);
	}
}
