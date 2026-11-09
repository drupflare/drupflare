<?php

/**
 * @file
 * Runs the same gd calls against real ext-gd and against the Shim\Gd queue, with tinyimg as host.
 *
 * Usage: /opt/homebrew/opt/php@8.3/bin/php tests/gd-differential.php [--measure] [--json=out.json] [filter]
 *
 * The host is `gd-differential-host.ts` under bun, which runs the worker's `runParkImage` against a
 * tinyimg checkout (TINYIMG_CHECKOUT, default the sibling `c-c++/tinyimg`) and a wasm module
 * (TINYIMG_WASM, default that checkout's `bin/tinyimg.wasm`). Each case prints PASS, FAIL with the
 * measured number, or REFUSED (the shim answered false and recorded a degradation).
 */

$root = __DIR__ . '/..';

spl_autoload_register(function (string $class) use ($root): void {
	if (str_starts_with($class, 'Drupal\\drupflare\\')) {
		$file = $root . '/src/' . str_replace('\\', '/', substr($class, 17)) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Http\Park;
use Drupal\drupflare\Shim\Gd;

if (!function_exists('imagecreatetruecolor') || !function_exists('imageavif')) {
	fwrite(STDERR, "needs real ext-gd with AVIF\n");
	exit(2);
}

if (!function_exists('vrzno_env')) {
	function vrzno_env(string $name): mixed
	{
		return $GLOBALS['cfw_test_host'][$name] ?? null;
	}
}
$GLOBALS['cfw_test_host'] = ['cfwParkImage' => true];

// #region the host process

final class HostProcess
{
	/** @var resource|null */
	private static $proc = null;

	/** @var array<int, resource> */
	private static array $pipes = [];

	public static int $calls = 0;

	public static function call(string $packed): string
	{
		if (self::$proc === null) {
			$checkout = getenv('TINYIMG_CHECKOUT') ?: __DIR__ . '/../../../c-c++/tinyimg';
			$wasm = getenv('TINYIMG_WASM') ?: $checkout . '/bin/tinyimg.wasm';
			$config = sys_get_temp_dir() . '/gd-differential-tsconfig-' . getmypid() . '.json';
			file_put_contents(
				$config,
				json_encode([
					'compilerOptions' => [
						'baseUrl' => '.',
						'paths' => [
							'@gmitch215/tinyimg' => [realpath($checkout) . '/src/ts/index.ts'],
							'@gmitch215/tinyimg/tinyimg.wasm' => [
								__DIR__ . '/gd-differential-wasm.ts',
							],
						],
					],
				]),
			);
			self::$proc = proc_open(
				['bun', '--tsconfig-override=' . $config, __DIR__ . '/gd-differential-host.ts'],
				[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
				self::$pipes,
				null,
				['TINYIMG_WASM' => $wasm] + getenv(),
			);
		}
		self::$calls++;
		fwrite(self::$pipes[0], $packed . "\n");
		$line = fgets(self::$pipes[1]);
		return $line === false ? '{"error":"the host process ended"}' : trim($line);
	}
}

Park::useDriver(function (string $target) {
	return str_starts_with($target, Gd::SCHEME)
		? HostProcess::call(substr($target, strlen(Gd::SCHEME)))
		: false;
});

// #endregion

// #region the two backends

final class Backend
{
	public function __construct(public bool $real) {}

	public function __call(string $name, array $args): mixed
	{
		return $this->real ? $name(...$args) : Gd::$name(...$args);
	}
}

$real = new Backend(true);
$shim = new Backend(false);

/**
 * Runs a closure and returns [value, throwable class and message or null].
 */
function attempt(callable $fn): array
{
	try {
		return [$fn(), null];
	} catch (Throwable $e) {
		return [null, get_class($e) . ': ' . $e->getMessage()];
	}
}

/**
 * The pixels of an encoded image as `[r, g, b, a8]` rows, read through real gd.
 */
function decode(string $bytes): array|false
{
	$im = @imagecreatefromstring($bytes);
	if ($im === false) {
		return false;
	}
	imagepalettetotruecolor($im);
	$w = imagesx($im);
	$h = imagesy($im);
	$out = [];
	for ($y = 0; $y < $h; $y++) {
		for ($x = 0; $x < $w; $x++) {
			$c = imagecolorat($im, $x, $y);
			$a = (127 - (($c >> 24) & 127)) * 2;
			$out[] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, $a];
		}
	}
	return ['w' => $w, 'h' => $h, 'px' => $out];
}

/**
 * Compares two decoded rasters over RGBA.
 *
 * @return array{w: int, h: int, exact: bool, max: int, psnr: float}|string
 *   The measures, or why they cannot be taken.
 */
function metrics(array|false $a, array|false $b): array|string
{
	if ($a === false || $b === false) {
		return 'an output did not decode';
	}
	if ($a['w'] !== $b['w'] || $a['h'] !== $b['h']) {
		return "size {$a['w']}x{$a['h']} against {$b['w']}x{$b['h']}";
	}
	$sum = 0;
	$max = 0;
	foreach ($a['px'] as $i => $p) {
		$q = $b['px'][$i];
		for ($k = 0; $k < 4; $k++) {
			$d = abs($p[$k] - $q[$k]);
			$sum += $d * $d;
			$max = max($max, $d);
		}
	}
	$mse = $sum / (4 * count($a['px']));
	return [
		'w' => $a['w'],
		'h' => $a['h'],
		'exact' => $max === 0,
		'max' => $max,
		'psnr' => $mse == 0 ? INF : 10 * log10((255 * 255) / $mse),
	];
}

/**
 * The gd2ref tool built from tests/gd2ref.c against libgd 2.3.3 with ENABLE_GD_FORMATS=1.
 *
 * Homebrew's PHP builds libgd without GD2 and GD, so native imagegd2() prints "GD2 image support
 * has been disabled" and writes nothing; the tool is the reference for the file format instead.
 * Build: cmake -S libgd-2.3.3 -B build -DENABLE_GD_FORMATS=1 -DBUILD_STATIC_LIBS=1
 * -DBUILD_SHARED_LIBS=0 -DENABLE_PNG=0 -DENABLE_JPEG=0 -DENABLE_TIFF=0 -DENABLE_WEBP=0
 * -DENABLE_HEIF=0 -DENABLE_AVIF=0 -DENABLE_FREETYPE=0 -DENABLE_FONTCONFIG=0 -DENABLE_XPM=0
 * -DENABLE_ICONV=0 -DENABLE_RAQM=0 -DBUILD_TEST=0, then
 * cc -I libgd-2.3.3/src -I build/src -o gd2ref tests/gd2ref.c build/Bin/libgd.a -lz -lm,
 * and run with GD2REF=/path/to/gd2ref.
 */
function gd2Tool(array $args, string $stdin): string|false
{
	$tool = getenv('GD2REF');
	if ($tool === false || !is_executable($tool)) {
		return false;
	}
	$in = tempnam(sys_get_temp_dir(), 'gd2in');
	$out = tempnam(sys_get_temp_dir(), 'gd2out');
	file_put_contents($in, $stdin);
	exec(
		escapeshellarg($tool) .
			' ' .
			implode(' ', array_map('escapeshellarg', $args)) .
			' < ' .
			escapeshellarg($in) .
			' > ' .
			escapeshellarg($out),
		$ignored,
		$code,
	);
	$bytes = (string) file_get_contents($out);
	unlink($in);
	unlink($out);
	return $code === 0 ? $bytes : false;
}

/**
 * The GD2 file libgd writes for a real true color handle.
 */
function gd2Reference(GdImage $im, int $chunk, int $mode): string|false
{
	$w = imagesx($im);
	$h = imagesy($im);
	$raw = '';
	for ($y = 0; $y < $h; $y++) {
		for ($x = 0; $x < $w; $x++) {
			$raw .= pack('N', imagecolorat($im, $x, $y));
		}
	}
	return gd2Tool(['write', $w, $h, $chunk, $mode, imagecolortransparent($im)], $raw);
}

/**
 * The raster libgd reads from a GD2 file, as `[r, g, b, a8]` rows.
 */
function gd2Decode(string $bytes): array|false
{
	$raw = gd2Tool(['read'], $bytes);
	if ($raw === false || strlen($raw) < 8) {
		return false;
	}
	$head = unpack('N2', $raw);
	$w = $head[1];
	$h = $head[2];
	$out = [];
	foreach (unpack('N*', substr($raw, 8)) as $c) {
		$out[] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, (127 - (($c >> 24) & 127)) * 2];
	}
	return ['w' => $w, 'h' => $h, 'px' => $out];
}

/**
 * The raster of a real handle, as `[r, g, b, a8]` rows.
 */
function handleRaster(GdImage $im): array
{
	$w = imagesx($im);
	$h = imagesy($im);
	$out = [];
	for ($y = 0; $y < $h; $y++) {
		for ($x = 0; $x < $w; $x++) {
			$c = imagecolorat($im, $x, $y);
			$out[] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, (127 - (($c >> 24) & 127)) * 2];
		}
	}
	return ['w' => $w, 'h' => $h, 'px' => $out];
}

function encode(
	Backend $g,
	GdImage|Drupal\drupflare\Shim\GdImage $im,
	string $format,
	int $q = -1,
): string {
	$g->imagesavealpha($im, true);
	ob_start();
	match ($format) {
		'png' => $g->imagepng($im),
		'jpeg' => $g->imagejpeg($im, null, $q),
		'webp' => $g->imagewebp($im, null, $q),
		'gif' => $g->imagegif($im),
		'avif' => $g->imageavif($im, null, $q),
	};
	return (string) ob_get_clean();
}

// #endregion

// #region sources, made once with real gd from the repository's fixtures

$fixtures = realpath(__DIR__ . '/../../../c-c++/tinyimg/tests/fixtures') ?: '';
$scaled = function (string $file, int $width) use ($fixtures): GdImage {
	$im = match (pathinfo($file, PATHINFO_EXTENSION)) {
		'jpg' => imagecreatefromjpeg("$fixtures/$file"),
		'png' => imagecreatefrompng("$fixtures/$file"),
		'webp' => imagecreatefromwebp("$fixtures/$file"),
		'avif' => imagecreatefromavif("$fixtures/$file"),
		'gif' => imagecreatefromgif("$fixtures/$file"),
	};
	return imagescale($im, $width);
};
$fromGd = function (GdImage $im, string $format, int $q = 90): string {
	imagesavealpha($im, true);
	ob_start();
	match ($format) {
		'png' => imagepng($im),
		'jpeg' => imagejpeg($im, null, $q),
		'webp' => imagewebp($im, null, $q),
		'gif' => imagegif($im),
		'avif' => imageavif($im, null, $q),
	};
	return (string) ob_get_clean();
};

$alphaSrc = $scaled('drupflare.png', 160);
$palette = $scaled('drupflare.png', 160);
imagetruecolortopalette($palette, false, 64);
$transparentIndex = imagecolorexactalpha($palette, 0, 0, 0, 127);
if ($transparentIndex < 0) {
	$transparentIndex = imagecolorallocatealpha($palette, 0, 0, 0, 127);
}
imagecolortransparent($palette, $transparentIndex);

$sources = [
	'jpeg_sf24' => [
		'bytes' => $fromGd($scaled('sf-24.jpg', 240), 'jpeg'),
		'alpha' => false,
		'palette' => false,
	],
	'jpeg_walk' => [
		'bytes' => $fromGd($scaled('long_walk.jpg', 200), 'jpeg'),
		'alpha' => false,
		'palette' => false,
	],
	'png_alpha' => ['bytes' => $fromGd($alphaSrc, 'png'), 'alpha' => true, 'palette' => false],
	'png_rgb' => [
		'bytes' => $fromGd($scaled('steam.png', 120), 'png'),
		'alpha' => false,
		'palette' => false,
	],
	'gif_palette' => ['bytes' => $fromGd($palette, 'gif'), 'alpha' => true, 'palette' => true],
	'webp' => [
		'bytes' => $fromGd($scaled('toyota_racing.webp', 240), 'webp'),
		'alpha' => false,
		'palette' => false,
	],
	'avif' => [
		'bytes' => $fromGd($scaled('dartmouth.avif', 200), 'avif'),
		'alpha' => false,
		'palette' => false,
	],
];
$sources['gif_ball'] = [
	'bytes' => (string) file_get_contents("$fixtures/ball_kick.gif"),
	'alpha' => false,
	'palette' => true,
];
$pngPalette = imagecreatetruecolor(40, 30);
imagetruecolortopalette($pngPalette, false, 16);
$sources['png_palette'] = [
	'bytes' => $fromGd($pngPalette, 'png'),
	'alpha' => false,
	'palette' => true,
];
$pixelSources = array_values(array_diff(array_keys($sources), ['gif_ball', 'png_palette']));

// #endregion

// #region cases

/** @var array<int, array{fn: string, name: string, sources: string[], run: callable, mode: string}> */
$cases = [];
$add = function (string $fn, string $name, array $srcs, callable $run, string $mode = 'pixel') use (
	&$cases,
): void {
	$cases[] = ['fn' => $fn, 'name' => $name, 'sources' => $srcs, 'run' => $run, 'mode' => $mode];
};
$truecolorCanvas = function (Backend $g, int $w, int $h, int|array $color) {
	$c = $g->imagecreatetruecolor($w, $h);
	$color = is_array($color) ? $g->imagecolorallocatealpha($c, ...$color) : $color;
	$g->imagealphablending($c, false);
	$g->imagefill($c, 0, 0, $color);
	$g->imagealphablending($c, true);
	return $c;
};
$allPixel = $pixelSources;

// passthrough: the decode-and-encode control, which isolates decoder differences
$add('imagecreatefromstring', 'decode and re-encode as png', $allPixel, fn($g, $im) => $im);
foreach (
	[
		'imagecreatefromjpeg' => 'jpeg_walk',
		'imagecreatefrompng' => 'png_alpha',
		'imagecreatefromwebp' => 'webp',
		'imagecreatefromgif' => 'gif_palette',
		'imagecreatefromavif' => 'avif',
	]
	as $fn => $src
) {
	$add($fn, 'reads a file of its format', [$src], function ($g, $im, $s) use ($fn) {
		$path = sys_get_temp_dir() . '/gdd-' . getmypid() . '.bin';
		file_put_contents($path, $s['bytes']);
		$out = $g->$fn($path);
		return $out;
	});
	$add(
		$fn,
		'false for the wrong format',
		['png_rgb' === $src ? 'jpeg_walk' : 'png_rgb'],
		function ($g, $im, $s) use ($fn) {
			$path = sys_get_temp_dir() . '/gdd-' . getmypid() . '.bin';
			file_put_contents($path, $s['bytes']);
			return @$g->$fn($path) === false;
		},
		'value',
	);
}
$add(
	'imagecreatetruecolor',
	'size and a black opaque canvas',
	['png_rgb'],
	fn($g) => $g->imagecreatetruecolor(33, 21),
);
$add(
	'imagesx',
	'width and height',
	$allPixel,
	fn($g, $im) => [$g->imagesx($im), $g->imagesy($im)],
	'value',
);
$add('imagesy', 'height', $allPixel, fn($g, $im) => $g->imagesy($im), 'value');
$add('imagedestroy', 'returns true', ['png_rgb'], fn($g, $im) => $g->imagedestroy($im), 'value');
$add(
	'imagealphablending',
	'returns true',
	['png_rgb'],
	fn($g, $im) => $g->imagealphablending($im, false),
	'value',
);
$add(
	'imagesavealpha',
	'returns true',
	['png_rgb'],
	fn($g, $im) => $g->imagesavealpha($im, true),
	'value',
);

foreach (
	[[0, 0, 0, 0, 60, 40, 'full'], [0, 0, 40, 20, 80, 50, 'region']]
	as [$dx, $dy, $sx, $sy, $sw, $sh, $label]
) {
	foreach (['imagecopyresampled', 'imagecopyresized'] as $fn) {
		$add($fn, "$fn $label", $allPixel, function ($g, $im) use ($fn, $sx, $sy, $sw, $sh) {
			$t = $g->imagecreatetruecolor(60, 40);
			$g->$fn($t, $im, 0, 0, $sx, $sy, 60, 40, $sw, $sh);
			return $t;
		});
	}
}
$add(
	'imagecopyresampled',
	'a canvas copied onto a canvas, as GDToolkit resizes then converts',
	$allPixel,
	function ($g, $im) use ($truecolorCanvas) {
		$w = $g->imagesx($im);
		$h = $g->imagesy($im);
		$a = $truecolorCanvas($g, 60, 40, [255, 255, 255, 0]);
		$g->imagecopyresampled($a, $im, 0, 0, 0, 0, 60, 40, $w, $h);
		$b = $truecolorCanvas($g, 60, 40, [255, 255, 255, 0]);
		$g->imagecopyresampled($b, $a, 0, 0, 0, 0, 60, 40, 60, 40);
		return $b;
	},
);
$add(
	'imagerotate',
	'a resized canvas turned 90 degrees, as GDToolkit rotates after a resize',
	$allPixel,
	function ($g, $im) use ($truecolorCanvas) {
		$w = $g->imagesx($im);
		$h = $g->imagesy($im);
		$a = $truecolorCanvas($g, 60, 40, [255, 255, 255, 0]);
		$g->imagecopyresampled($a, $im, 0, 0, 0, 0, 60, 40, $w, $h);
		return $g->imagerotate($a, 90.0, $g->imagecolorallocatealpha($a, 255, 0, 0, 0));
	},
);
$add('imagescale', 'width only', $allPixel, fn($g, $im) => $g->imagescale($im, 70));
$add(
	'imagescale',
	'nearest neighbour',
	$allPixel,
	fn($g, $im) => $g->imagescale($im, 70, 45, IMG_NEAREST_NEIGHBOUR),
);
$add(
	'imagecrop',
	'inside the bounds',
	$allPixel,
	fn($g, $im) => $g->imagecrop($im, ['x' => 5, 'y' => 7, 'width' => 40, 'height' => 30]),
);
foreach ([90.0, 180.0, 270.0, 37.0] as $angle) {
	// libgd 2.3.3 aborts the process on imagerotate(180) of an indexed image, so it cannot be the reference
	$add(
		'imagerotate',
		"angle $angle",
		$angle === 180.0 ? array_values(array_diff($allPixel, ['gif_palette'])) : $allPixel,
		fn($g, $im) => $g->imagerotate($im, $angle, $g->imagecolorallocatealpha($im, 255, 0, 0, 0)),
	);
}

$add('imagecopy', 'onto a filled canvas at an offset', $allPixel, function ($g, $im) use (
	$truecolorCanvas,
) {
	$w = $g->imagesx($im);
	$h = $g->imagesy($im);
	$c = $truecolorCanvas($g, $w + 20, $h + 10, [10, 200, 30, 0]);
	$g->imagecopy($c, $im, 7, 5, 3, 2, intdiv($w, 2), intdiv($h, 2));
	return $c;
});
$add('imagecopy', 'blending off, so alpha is stored', $allPixel, function ($g, $im) use (
	$truecolorCanvas,
) {
	$w = $g->imagesx($im);
	$h = $g->imagesy($im);
	$c = $truecolorCanvas($g, $w, $h, [10, 200, 30, 0]);
	$g->imagealphablending($c, false);
	$g->imagecopy($c, $im, 0, 0, 0, 0, $w, $h);
	return $c;
});
$add('imagecopy', 'a destination edge clips the copy', $allPixel, function ($g, $im) use (
	$truecolorCanvas,
) {
	$c = $truecolorCanvas($g, 50, 40, 0);
	$g->imagecopy($c, $im, 30, 25, 0, 0, 40, 40);
	return $c;
});
foreach ([25, 50, 100] as $pct) {
	$add('imagecopymerge', "merge $pct percent", $allPixel, function ($g, $im) use (
		$truecolorCanvas,
		$pct,
	) {
		$w = $g->imagesx($im);
		$h = $g->imagesy($im);
		$c = $truecolorCanvas($g, $w, $h, [200, 40, 90, 0]);
		$g->imagecopymerge($c, $im, 0, 0, 0, 0, $w, $h, $pct);
		return $c;
	});
}
$add('imagecopymerge', 'merge 0 leaves the target', ['png_alpha'], function ($g, $im) use (
	$truecolorCanvas,
) {
	$c = $truecolorCanvas($g, 40, 40, [200, 40, 90, 0]);
	$g->imagecopymerge($c, $im, 0, 0, 0, 0, 40, 40, 0);
	return $c;
});
foreach (
	[[0x00336699, 'opaque'], [0x40336699, 'half alpha, blending on'], [0x7f336699, 'clear']]
	as [$color, $label]
) {
	$add('imagefill', "blank canvas, $label", ['png_rgb'], function ($g) use ($color) {
		$c = $g->imagecreatetruecolor(30, 20);
		$g->imagefill($c, 4, 3, $color);
		return $c;
	});
}
$add('imagefill', 'a seed outside the image does nothing', ['png_rgb'], function ($g) {
	$c = $g->imagecreatetruecolor(30, 20);
	$g->imagefill($c, 99, 99, 0x00ff00ff);
	return $c;
});
foreach ([1, 2, 3] as $mode) {
	$add('imageflip', "mode $mode", $allPixel, function ($g, $im) use ($mode) {
		$g->imageflip($im, $mode);
		return $im;
	});
}
foreach (
	[
		[IMG_FILTER_NEGATE, [], 'negate'],
		[IMG_FILTER_GRAYSCALE, [], 'grayscale'],
		[IMG_FILTER_BRIGHTNESS, [40], 'brightness +40'],
		[IMG_FILTER_BRIGHTNESS, [-60], 'brightness -60'],
		[IMG_FILTER_CONTRAST, [30], 'contrast 30'],
		[IMG_FILTER_CONTRAST, [-40], 'contrast -40'],
		[IMG_FILTER_EDGEDETECT, [], 'edgedetect'],
		[IMG_FILTER_EMBOSS, [], 'emboss'],
		[IMG_FILTER_MEAN_REMOVAL, [], 'mean removal'],
		[IMG_FILTER_SMOOTH, [5], 'smooth 5'],
		[IMG_FILTER_SMOOTH, [-3], 'smooth -3'],
	]
	as [$type, $args, $label]
) {
	$add('imagefilter', $label, $allPixel, function ($g, $im) use ($type, $args) {
		$w = $g->imagesx($im);
		$h = $g->imagesy($im);
		$t = $g->imagecreatetruecolor($w, $h);
		$g->imagealphablending($t, false);
		$g->imagecopy($t, $im, 0, 0, 0, 0, $w, $h);
		$g->imagefilter($t, $type, ...$args);
		return $t;
	});
}
$add(
	'imagefilter',
	'a filter outside the eight is refused',
	['png_rgb'],
	function ($g, $im) {
		return $g->imagefilter($im, IMG_FILTER_PIXELATE, 4, true);
	},
	'value',
);

foreach (['png', 'jpeg', 'webp', 'gif', 'avif'] as $format) {
	$add(
		'image' . ($format === 'jpeg' ? 'jpeg' : $format),
		"encodes $format",
		['jpeg_sf24', 'png_alpha', 'webp', 'avif'],
		fn($g, $im) => $im,
		"encode:$format",
	);
}

$colorHandles = [
	'jpeg_sf24',
	'png_alpha',
	'gif_palette',
	'gif_ball',
	'png_palette',
	'webp',
	'avif',
];
$add(
	'imageistruecolor',
	'by source',
	$colorHandles,
	fn($g, $im) => $g->imageistruecolor($im),
	'value',
);
$add(
	'imagecolorstotal',
	'by source',
	$colorHandles,
	fn($g, $im) => $g->imagecolorstotal($im),
	'value',
);
$add(
	'imagecolortransparent',
	'read by source',
	$colorHandles,
	fn($g, $im) => $g->imagecolortransparent($im),
	'value',
);
$add(
	'imagecolortransparent',
	'set then read',
	['png_rgb', 'gif_palette'],
	function ($g, $im) {
		$c = $g->imagecolorallocatealpha($im, 1, 2, 3, 0);
		$set = $g->imagecolortransparent($im, $c);
		return [
			$set === $c,
			$g->imagecolortransparent($im) === $c,
			$g->imagecolorsforindex($im, $c),
		];
	},
	'value',
);
$add(
	'imagecolorallocatealpha',
	'a true color handle packs, an indexed one grows',
	$colorHandles,
	function ($g, $im) {
		$before = $g->imagecolorstotal($im);
		$c = $g->imagecolorallocatealpha($im, 12, 34, 56, 78);
		return [$c, $g->imagecolorstotal($im) - $before, $g->imagecolorsforindex($im, $c)];
	},
	'value',
);
$add(
	'imagecolorallocate',
	'a true color handle packs, an indexed one grows',
	$colorHandles,
	function ($g, $im) {
		$before = $g->imagecolorstotal($im);
		$c = $g->imagecolorallocate($im, 12, 34, 56);
		return [$c, $g->imagecolorstotal($im) - $before, $g->imagecolorsforindex($im, $c)];
	},
	'value',
);
$add(
	'imagecolorallocate',
	'a channel out of range throws',
	['png_rgb'],
	fn($g, $im) => $g->imagecolorallocate($im, 0, 256, 0),
	'value',
);
$add('imagecolorallocate', 'drupal CreateNew: white fill on a fresh canvas', ['png_rgb'], function (
	$g,
) {
	$c = $g->imagecreatetruecolor(30, 20);
	$g->imagefill($c, 0, 0, $g->imagecolorallocate($c, 255, 255, 255));
	return $c;
});
$add(
	'imagecolorsforindex',
	'first entries',
	$colorHandles,
	function ($g, $im) {
		return $g->imagecolorstotal($im) > 0
			? [
				$g->imagecolorsforindex($im, 0),
				$g->imagecolorsforindex($im, $g->imagecolorstotal($im) - 1),
			]
			: $g->imagecolorsforindex($im, 0x40a0b0c0);
	},
	'value',
);
$add(
	'imagecolorsforindex',
	'an index outside the table throws',
	['gif_palette'],
	fn($g, $im) => $g->imagecolorsforindex($im, 9999),
	'value',
);
$add(
	'imagecolorexactalpha',
	'present and absent',
	$colorHandles,
	function ($g, $im) {
		$c = $g->imagecolorallocatealpha($im, 9, 8, 7, 6);
		return [
			$g->imagecolorexactalpha($im, 9, 8, 7, 6) === $c,
			$g->imagecolorexactalpha($im, 250, 251, 252, 0),
		];
	},
	'value',
);
$add(
	'imagecolorallocatealpha',
	'a channel out of range throws',
	['png_rgb'],
	fn($g, $im) => $g->imagecolorallocatealpha($im, 256, 0, 0, 0),
	'value',
);
$add(
	'imagecolorallocatealpha',
	'alpha out of range throws',
	['png_rgb'],
	fn($g, $im) => $g->imagecolorallocatealpha($im, 0, 0, 0, 128),
	'value',
);
$add(
	'imagetruecolortopalette',
	'returns and flips imageistruecolor',
	['png_rgb', 'gif_palette'],
	function ($g, $im) {
		return [$g->imagetruecolortopalette($im, false, 32), $g->imageistruecolor($im)];
	},
	'value',
);
$add(
	'imagetruecolortopalette',
	'zero colors throws',
	['png_rgb'],
	fn($g, $im) => $g->imagetruecolortopalette($im, false, 0),
	'value',
);
$add(
	'imageinterlace',
	'default, set, read',
	['png_rgb'],
	fn($g, $im) => [
		$g->imageinterlace($im),
		$g->imageinterlace($im, true),
		$g->imageinterlace($im),
	],
	'value',
);
$add(
	'imageinterlace',
	'progressive jpeg and interlaced png',
	['jpeg_sf24', 'png_rgb'],
	fn($g, $im) => $im,
	'interlace',
);
$add(
	'imageantialias',
	'returns true on both kinds',
	['png_rgb', 'gif_palette'],
	fn($g, $im) => $g->imageantialias($im, true),
	'value',
);
$add(
	'imagetypes',
	'bits the shim reports are bits gd has',
	['png_rgb'],
	fn($g) => $g->imagetypes(),
	'bits',
);
$add('gd_info', 'keys the shim reports as supported', ['png_rgb'], fn($g) => $g->gd_info(), 'info');

// imagegd2: raw and compressed true color files, compared by header bytes and by decoding both
foreach (
	[
		['raw, default chunk and mode', 'd', 'd', $allPixel],
		['raw, chunk 77 that divides no extent', '77', '1', $allPixel],
		['compressed, chunk 64 that divides no extent', '64', '2', $allPixel],
		['compressed, chunk 100', '100', '2', ['png_alpha', 'jpeg_walk']],
		['chunk -1 is 128, mode 0 writes compressed', '-1', '0', ['png_rgb']],
		['chunk 5000 clamps to 4096, mode 9 writes compressed', '5000', '9', ['png_rgb']],
		['chunk 3 clamps to 64, raw', '3', '1', ['png_rgb']],
	]
	as [$label, $chunk, $gmode, $srcs]
) {
	$add('imagegd2', $label, $srcs, fn($g, $im) => $im, "gd2:$chunk:$gmode");
}
$add(
	'imagegd2',
	'a canvas with a transparent color and a translucent fill',
	['png_rgb'],
	function ($g, $im) use ($truecolorCanvas) {
		$c = $truecolorCanvas($g, 70, 50, [200, 100, 50, 64]);
		$g->imagecolortransparent($c, $g->imagecolorallocatealpha($c, 1, 2, 3, 4));
		return $c;
	},
	'gd2:64:1',
);
$add(
	'imagegd2',
	'after a queued crop and flip',
	['png_alpha', 'jpeg_sf24'],
	function ($g, $im) {
		$c = $g->imagecrop($im, ['x' => 5, 'y' => 7, 'width' => 40, 'height' => 30]);
		$g->imageflip($c, IMG_FLIP_BOTH);
		return $c;
	},
	'gd2:64:2',
);
$add(
	'imagegd2',
	'signature: parameter names and defaults',
	['png_rgb'],
	function ($g) {
		$f = $g->real
			? new ReflectionFunction('imagegd2')
			: new ReflectionMethod(Gd::class, 'imagegd2');
		return array_map(
			fn(ReflectionParameter $p) => [
				$p->getName(),
				$p->allowsNull(),
				$p->isDefaultValueAvailable() ? $p->getDefaultValue() : '(required)',
			],
			$f->getParameters(),
		);
	},
	'value',
);
$add(
	'imagegd2',
	'a chunk size past the C int is false',
	['png_rgb'],
	fn($g, $im) => @$g->imagegd2($im, null, 2 ** 31),
	'value',
);
$add(
	'imagegd2',
	'the constants match',
	['png_rgb'],
	fn($g) => [IMG_GD2_RAW, IMG_GD2_COMPRESSED],
	'value',
);

// #endregion

// #region the run

$only = null;
$measure = false;
$json = null;
foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--measure') {
		$measure = true;
	} elseif (str_starts_with($arg, '--json=')) {
		$json = substr($arg, 7);
	} else {
		$only = $arg;
	}
}

/** @var array<string, array<string, float>> $floors the measured PSNR floor per case name */
$floors = is_file(__DIR__ . '/gd-differential-floors.json')
	? json_decode((string) file_get_contents(__DIR__ . '/gd-differential-floors.json'), true)
	: [];

$results = [];
$record = function (
	string $fn,
	string $name,
	string $src,
	string $status,
	string $detail,
	?float $psnr = null,
	?bool $exact = null,
) use (&$results): void {
	$results[] = compact('fn', 'name', 'src', 'status', 'detail', 'psnr', 'exact');
	printf("%-8s %-24s %-44s %-12s %s\n", $status, $fn, $name, $src, $detail);
};

foreach ($cases as $case) {
	if ($only !== null && !str_contains($case['fn'] . ' ' . $case['name'], $only)) {
		continue;
	}
	foreach ($case['sources'] as $key) {
		$s = $sources[$key] + ['key' => $key];
		Degradation::reset();
		[$ra, $ea] = attempt(
			fn() => $case['run']($real, $real->imagecreatefromstring($s['bytes']), $s),
		);
		Degradation::reset();
		[$rb, $eb] = attempt(
			fn() => $case['run']($shim, $shim->imagecreatefromstring($s['bytes']), $s),
		);
		$degraded = array_filter(Degradation::all(), fn($d) => $d['state'] === 'blocked') !== [];
		$mode = $case['mode'];
		if ($ea !== null || $eb !== null) {
			$record(
				$case['fn'],
				$case['name'],
				$key,
				$ea === $eb ? 'PASS' : 'FAIL',
				'throws: real=' . var_export($ea, true) . ' shim=' . var_export($eb, true),
			);
			continue;
		}
		if (str_starts_with($mode, 'gd2:')) {
			[, $chunk, $gmode] = explode(':', $mode);
			$args = static fn(): array => $chunk === 'd' ? [] : [null, (int) $chunk, (int) $gmode];
			ob_start();
			$shim->imagegd2($rb, ...$args());
			$b = (string) ob_get_clean();
			$blocked = array_filter(Degradation::all(), fn($d) => $d['state'] === 'blocked') !== [];
			if (!imageistruecolor($ra)) {
				$record(
					$case['fn'],
					$case['name'],
					$key,
					$blocked && $b === '' ? 'REFUSED' : 'FAIL',
					$blocked && $b === ''
						? (string) (array_values(Degradation::all())[0]['reason'] ?? '')
						: 'an indexed handle must be refused',
				);
				continue;
			}
			$cs = $chunk === 'd' || (int) $chunk === -1 ? 128 : (int) $chunk;
			$a = gd2Reference($ra, $cs, $chunk === 'd' ? 1 : (int) $gmode);
			if ($a === false || $b === '') {
				$record(
					$case['fn'],
					$case['name'],
					$key,
					'FAIL',
					$a === false
						? 'the libgd reference tool is not built (GD2REF)'
						: 'the shim wrote nothing',
				);
				continue;
			}
			$parse = static function (string $bytes): array {
				$fmt = (ord($bytes[12]) << 8) | ord($bytes[13]);
				$ncx = (ord($bytes[14]) << 8) | ord($bytes[15]);
				$ncy = (ord($bytes[16]) << 8) | ord($bytes[17]);
				$colors = 18 + ($fmt === 4 ? 8 * $ncx * $ncy : 0);
				return [substr($bytes, 0, 18), substr($bytes, $colors, 5), $fmt];
			};
			[$ha, $ca] = $parse($a);
			[$hb, $cb, $fb] = $parse($b);
			if ($ha !== $hb || $ca !== $cb) {
				$record(
					$case['fn'],
					$case['name'],
					$key,
					'FAIL',
					'header ' .
						bin2hex($ha) .
						'/' .
						bin2hex($ca) .
						' against ' .
						bin2hex($hb) .
						'/' .
						bin2hex($cb),
				);
				continue;
			}
			$control = metrics(handleRaster($ra), gd2Decode($a));
			if (is_string($control) || !$control['exact']) {
				$record(
					$case['fn'],
					$case['name'],
					$key,
					'FAIL',
					'the reference does not read back its own file: ' .
						(is_string($control) ? $control : 'max ' . $control['max']),
				);
				continue;
			}
			$m = metrics(gd2Decode($a), gd2Decode($b));
			if (is_string($m)) {
				$record($case['fn'], $case['name'], $key, 'FAIL', $m);
				continue;
			}
			$floor = $floors[$case['fn'] . ' ' . $case['name']][$key] ?? null;
			$ok = $m['exact'] || ($floor !== null && $m['psnr'] >= $floor);
			$record(
				$case['fn'],
				$case['name'],
				$key,
				$measure ? 'MEASURED' : ($ok ? 'PASS' : 'FAIL'),
				sprintf(
					'%s psnr=%s max=%d fmt=%d bytes %d vs %d same-bytes=%s floor=%s',
					$m['exact'] ? 'exact' : 'inexact',
					is_infinite($m['psnr']) ? 'inf' : sprintf('%.2f', $m['psnr']),
					$m['max'],
					$fb,
					strlen($a),
					strlen($b),
					$a === $b ? 'yes' : 'no',
					$floor ?? 'none',
				),
				is_infinite($m['psnr']) ? null : $m['psnr'],
				$m['exact'],
			);
			continue;
		}
		if (str_starts_with($mode, 'encode:') || $mode === 'interlace') {
			$format = str_starts_with($mode, 'encode:')
				? substr($mode, 7)
				: ($key === 'jpeg_sf24'
					? 'jpeg'
					: 'png');
			$q = $format === 'png' || $format === 'gif' ? -1 : 80;
			if ($mode === 'interlace') {
				$g1 = $real->imageinterlace($ra, true);
				$g2 = $shim->imageinterlace($rb, true);
			}
			$a = encode($real, $ra, $format, $q);
			$b = encode($shim, $rb, $format, $q);
			if ($mode === 'interlace') {
				if (
					$format === 'png' &&
					array_filter(Degradation::all(), fn($d) => $d['state'] === 'blocked') !== []
				) {
					$record(
						$case['fn'],
						$case['name'],
						$key,
						'REFUSED',
						'png is written without interlacing; a degradation is recorded',
					);
					continue;
				}
				$marker = fn(string $bytes) => $format === 'jpeg'
					? str_contains($bytes, "\xff\xc2")
					: ord($bytes[28]) === 1;
				$record(
					$case['fn'],
					$case['name'],
					$key,
					$marker($a) === $marker($b) && $marker($b) ? 'PASS' : 'FAIL',
					'real=' .
						var_export($marker($a), true) .
						' shim=' .
						var_export($marker($b), true),
				);
				continue;
			}
			$refused = array_filter(Degradation::all(), fn($d) => $d['state'] === 'blocked') !== [];
			if (($degraded || $refused) && $b === '') {
				$record(
					$case['fn'],
					$case['name'],
					$key,
					'REFUSED',
					(string) (array_values(Degradation::all())[0]['reason'] ?? ''),
				);
				continue;
			}
			$m = metrics(decode($a), decode($b));
			$floorKey = $case['fn'] . ' ' . $case['name'];
			if (is_string($m)) {
				$record(
					$case['fn'],
					$case['name'],
					$key,
					'FAIL',
					$m . ' (' . strlen($a) . ' vs ' . strlen($b) . ' bytes)',
				);
				continue;
			}
			$floor = $floors[$floorKey][$key] ?? null;
			$ok = $m['exact'] || ($floor !== null && $m['psnr'] >= $floor);
			$record(
				$case['fn'],
				$case['name'],
				$key,
				$measure ? 'MEASURED' : ($ok ? 'PASS' : 'FAIL'),
				sprintf(
					'psnr=%.2f max=%d bytes %d vs %d floor=%s',
					$m['psnr'],
					$m['max'],
					strlen($a),
					strlen($b),
					$floor ?? 'none',
				),
				is_infinite($m['psnr']) ? null : $m['psnr'],
				$m['exact'],
			);
			continue;
		}
		if ($mode === 'value' || $mode === 'bits' || $mode === 'info') {
			if ($mode === 'bits') {
				$ok = ($rb & ~$ra) === 0;
				$record(
					$case['fn'],
					$case['name'],
					$key,
					$ok ? 'PASS' : 'FAIL',
					"real=$ra shim=$rb",
				);
				continue;
			}
			if ($mode === 'info') {
				$bad = [];
				foreach ($rb as $k => $v) {
					if ($k !== 'GD Version' && $v === true && ($ra[$k] ?? false) !== true) {
						$bad[] = $k;
					}
				}
				$record(
					$case['fn'],
					$case['name'],
					$key,
					$bad === [] ? 'PASS' : 'FAIL',
					$bad === [] ? 'every supported key is supported by gd' : implode(',', $bad),
				);
				continue;
			}
			if ($degraded) {
				$record(
					$case['fn'],
					$case['name'],
					$key,
					'REFUSED',
					(string) (array_values(Degradation::all())[0]['reason'] ?? ''),
				);
				continue;
			}
			$same = $ra === $rb;
			$record(
				$case['fn'],
				$case['name'],
				$key,
				$same ? 'PASS' : 'FAIL',
				$same ? json_encode($ra) : 'real=' . json_encode($ra) . ' shim=' . json_encode($rb),
			);
			continue;
		}
		// pixel cases return a handle, or false when the shim refused
		if ($ra === false) {
			$record(
				$case['fn'],
				$case['name'],
				$key,
				$rb === false ? 'PASS' : 'FAIL',
				'real gd returned false',
			);
			continue;
		}
		if ($rb === false || $degraded) {
			$record(
				$case['fn'],
				$case['name'],
				$key,
				'REFUSED',
				(string) (array_values(Degradation::all())[0]['reason'] ?? 'returned false'),
			);
			continue;
		}
		$a = encode($real, $ra, 'png');
		$b = encode($shim, $rb, 'png');
		$m = metrics(decode($a), decode($b));
		if (is_string($m)) {
			$record($case['fn'], $case['name'], $key, 'FAIL', $m);
			continue;
		}
		$floorKey = $case['fn'] . ' ' . $case['name'];
		$floor = $floors[$floorKey][$key] ?? null;
		$ok = $m['exact'] || ($floor !== null && $m['psnr'] >= $floor);
		$status = $measure ? 'MEASURED' : ($ok ? 'PASS' : 'FAIL');
		$record(
			$case['fn'],
			$case['name'],
			$key,
			$status,
			sprintf(
				'%s psnr=%s max=%d floor=%s',
				$m['exact'] ? 'exact' : 'inexact',
				is_infinite($m['psnr']) ? 'inf' : sprintf('%.2f', $m['psnr']),
				$m['max'],
				$floor ?? 'none',
			),
			is_infinite($m['psnr']) ? null : $m['psnr'],
			$m['exact'],
		);
	}
}

$tally = array_count_values(array_column($results, 'status'));
echo "\nphp " .
	PHP_VERSION .
	' host calls ' .
	HostProcess::$calls .
	' ' .
	json_encode($tally) .
	"\n";
if ($json !== null) {
	file_put_contents(
		$json,
		json_encode(['php' => PHP_VERSION, 'results' => $results], JSON_PRETTY_PRINT),
	);
}
exit(($tally['FAIL'] ?? 0) > 0 ? 1 : 0);

// #endregion
