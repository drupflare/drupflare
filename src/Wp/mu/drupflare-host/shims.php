<?php

/**
 * Where each neutral shim class sits inside lib/, which is where the pack mounts them.
 *
 * Only these names resolve through the loader. The classes are MIT and carry the namespace they
 * have in the Drupal module, so the mu plugin depends on them and they never import it.
 *
 * @return array<string, string>
 */
function drupflare_host_lib_classes(): array
{
	return [
		'Drupal\\drupflare\\Degradation' => 'Degradation.php',
		'Drupal\\drupflare\\Host' => 'Host.php',
		'Drupal\\drupflare\\Http\\Park' => 'Http/Park.php',
		'Drupal\\drupflare\\Http\\ParkSession' => 'Http/ParkSession.php',
		'Drupal\\drupflare\\Shim\\Gd' => 'Shim/Gd.php',
		'Drupal\\drupflare\\Shim\\GdImage' => 'Shim/GdImage.php',
		'Drupal\\drupflare\\Shim\\Exif' => 'Shim/Exif.php',
	];
}

function drupflare_host_lib_dir(): string
{
	return defined('DRUPFLARE_HOST_LIB') ? rtrim(DRUPFLARE_HOST_LIB, '/') : __DIR__ . '/lib';
}

function drupflare_host_lib_autoload(string $class): void
{
	$file = drupflare_host_lib_classes()[$class] ?? null;
	if ($file !== null && is_file(drupflare_host_lib_dir() . '/' . $file)) {
		require_once drupflare_host_lib_dir() . '/' . $file;
	}
}

spl_autoload_register('drupflare_host_lib_autoload');
