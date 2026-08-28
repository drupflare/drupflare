<?php

/**
 * @file
 * Loads every class in this module against a real Drupal root.
 *
 * Also drives the behaviour that does not need a Worker.
 *
 * CfwImageToolkit was lint-clean for a
 * day with a guaranteed fatal in it:
 *
 *   Fatal error: Class Drupal\drupflare\Plugin\ImageToolkit\CfwImageToolkit contains 2
 *   abstract methods and must therefore be declared abstract or implement the
 *   remaining methods (PluginFormInterface::buildConfigurationForm,
 *   ::submitConfigurationForm)
 *
 * ImageToolkitBase implements PluginFormInterface but leaves those two abstract, so
 * the class was not loadable at all. That is a linkage error against real Drupal,
 * raised the first time something autoloads the class, and `php -l` is blind to it.
 * This is the gate that catches the next one.
 *
 * Nothing here runs inside a Worker, so no host function is
 * installed. Every capability is therefore exercised in its ABSENT state, which is
 * the control the runtime round could not provide: a missing capability must refuse
 * by name rather than return something plausible. The 26 assertions that drive these
 * classes with the host functions present live in drupflare/worker, route
 * `/capability`.
 *
 * Usage:
 *   php tests/load-classes.php [/path/to/drupal-root]
 *
 * The Drupal root must be a checkout with vendor/ installed; it is only read.
 */

declare(strict_types=1);

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\ImageToolkit\Attribute\ImageToolkit;
use Drupal\Core\ImageToolkit\ImageToolkitBase;
use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Cache\DatabaseBackendFactory;
use Drupal\Core\Routing\MatcherDumper;
use Drupal\drupflare\Hook\Requirements;
use Drupal\drupflare\Host;
use Drupal\drupflare\Install\Requirements\DrupflareRequirements;
use Drupal\drupflare\Plugin\ImageToolkit\CfwImageToolkit;
use Drupal\drupflare\Plugin\Mail\CfwMail;
use Drupal\drupflare\Cache\CfwCacheBackend;
use Drupal\drupflare\Cache\CfwCacheBackendFactory;
use Drupal\drupflare\Routing\CfwMatcherDumper;
use Drupal\drupflare\StreamWrapper\HttpsStreamWrapper;
use Drupflare\StreamHttp\HttpsStreamWrapper as BaseHttpsStreamWrapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

$module = dirname(__DIR__);
$root = $argv[1] ?? getenv('DRUPAL_ROOT') ?: null;
if ($root === null || !is_file($root . '/vendor/autoload.php')) {
	fwrite(STDERR, "Pass a Drupal 11.3+ root with vendor/ installed, or set DRUPAL_ROOT.\n");
	exit(2);
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

// a fatal during class declaration is not catchable, so say which file was being
// loaded rather than leaving a bare stack-less message
register_shutdown_function(static function (): void {
	$error = error_get_last();
	if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) {
		fwrite(
			STDERR,
			"\nFATAL while loading the module: {$error['message']}\n  at {$error['file']}:{$error['line']}\n",
		);
	}
});

$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('Drupal\\drupflare\\', $module . '/src');

// drupflare/stream-http, which HttpsStreamWrapper extends. The Drupal root's autoloader was built
// for Drupal's own dependencies and knows nothing about this module's, so without this the class
// fatals on a missing parent the moment anything autoloads it -- which is precisely the failure
// this suite exists to catch, so it is registered rather than skipped. Prefers the module's own
// vendor/ and falls back to a sibling checkout, because a workspace has one and CI has the other.
foreach (
	[$module . '/vendor/drupflare/stream-http/src', dirname($module) . '/stream-http/src']
	as $streamHttp
) {
	if (is_dir($streamHttp)) {
		$loader->addPsr4('Drupflare\\StreamHttp\\', $streamHttp);
		break;
	}
}

$pass = 0;
$fail = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $label
 *   What the assertion claims.
 * @param bool $condition
 *   The result of evaluating that claim.
 * @param string $detail
 *   Extra context printed only on failure.
 */
function ok(string $label, bool $condition, string $detail = ''): void
{
	global $pass, $fail;
	if ($condition) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
	}
}

// --- every class declares cleanly ------------------------------------------
echo "Class declaration\n";

$classes = [
	'Drupal\drupflare\Host',
	'Drupal\drupflare\RequestResetter',
	'Drupal\drupflare\DrupflareServiceProvider',
	'Drupal\drupflare\Http\FetchHandler',
	'Drupal\drupflare\Plugin\ImageToolkit\CfwImageToolkit',
	'Drupal\drupflare\Logger\CfwLogger',
	'Drupal\drupflare\Plugin\Mail\CfwMail',
	'Drupal\drupflare\Queue\CfwDeferredHttp',
	'Drupal\drupflare\StreamWrapper\HttpsStreamWrapper',
	'Drupal\drupflare\Hook\Requirements',
	'Drupal\drupflare\Install\Requirements\DrupflareRequirements',
];
foreach ($classes as $class) {
	ok("$class loads", class_exists($class));
}

// isInstantiable() is the direct assertion of the CfwImageToolkit defect: a class
// left with an inherited abstract method is not instantiable, and the fatal above
// is what PHP raises instead of answering FALSE
foreach ($classes as $class) {
	if (!class_exists($class)) {
		continue;
	}
	$reflection = new ReflectionClass($class);
	ok("$class has no unimplemented abstract methods", $reflection->isInstantiable());
}

// --- the contracts Drupal discovers them through --------------------------
echo "\nContracts\n";
ok('CfwMail is a mail plugin', is_a(CfwMail::class, MailInterface::class, true));
ok(
	'CfwImageToolkit is an image toolkit',
	is_a(CfwImageToolkit::class, ImageToolkitBase::class, true),
);
ok(
	'CfwLogger is a PSR-3 logger',
	is_a('Drupal\drupflare\Logger\CfwLogger', LoggerInterface::class, true),
);
ok(
	'DrupflareServiceProvider is a service provider',
	is_a(
		'Drupal\drupflare\DrupflareServiceProvider',
		'Drupal\Core\DependencyInjection\ServiceProviderInterface',
		true,
	),
);

$mailAttributes = (new ReflectionClass(CfwMail::class))->getAttributes(Mail::class);
ok('CfwMail carries a Mail attribute', count($mailAttributes) === 1);
ok(
	'the mail plugin id is cfw_mail',
	count($mailAttributes) === 1 && $mailAttributes[0]->newInstance()->id === 'cfw_mail',
);

$toolkitAttributes = (new ReflectionClass(CfwImageToolkit::class))->getAttributes(
	ImageToolkit::class,
);
ok('CfwImageToolkit carries an ImageToolkit attribute', count($toolkitAttributes) === 1);
ok(
	'the toolkit id is cfw_images',
	count($toolkitAttributes) === 1 && $toolkitAttributes[0]->newInstance()->id === 'cfw_images',
);

// --- the module metadata points at classes that exist ---------------------
echo "\nModule metadata\n";
$info = Yaml::parseFile($module . '/drupflare.info.yml');
ok('info.yml declares a module', ($info['type'] ?? null) === 'module');
ok('info.yml declares a core requirement', !empty($info['core_version_requirement']));

$services = Yaml::parseFile($module . '/drupflare.services.yml');
ok(
	'services.yml declares services',
	is_array($services['services'] ?? null) && $services['services'] !== [],
);
foreach ($services['services'] ?? [] as $id => $definition) {
	$class = $definition['class'] ?? '';
	ok("service $id resolves its class", $class !== '' && class_exists($class), $class);
}

// --- absent capabilities refuse by name ----------------------------------
// This is the control. vrzno_env() does not exist outside the interpreter, so every
// capability is missing, and "missing" must be reported rather than faked.
echo "\nAbsent capabilities\n";
ok('vrzno_env is absent in a normal PHP process', !function_exists('vrzno_env'));
ok('Host::fn returns NULL for a capability that is not installed', Host::fn('cfwMail') === null);
ok('Host::has is FALSE for a capability that is not installed', Host::has('cfwMail') === false);

$reply = Host::call('cfwMail', ['to' => 'nobody@example.com']);
ok('Host::call refuses rather than throwing', ($reply['ok'] ?? null) === false);
ok(
	'the refusal names the capability',
	str_contains($reply['error'] ?? '', 'cfwMail'),
	$reply['error'] ?? '',
);
ok(
	'the refusal names the deployment as the cause',
	str_contains($reply['error'] ?? '', 'not installed in this deployment'),
	$reply['error'] ?? '',
);

$mail = new CfwMail();
$formatted = $mail->format(['body' => ['first', 'second'], 'params' => []]);
ok(
	'format() joins the body parts',
	str_contains($formatted['body'], 'first') && str_contains($formatted['body'], 'second'),
);
ok('format() leaves no html part for a text mail', !isset($formatted['html']));

// MailFormatHelper::htmlToText() reads the $base_path/$base_url globals a bootstrapped
// Drupal sets; without them core's own URL callback deprecates on preg_quote(NULL)
$GLOBALS['base_path'] = '/';
$GLOBALS['base_url'] = 'http://localhost';

$html = $mail->format(['body' => ['<p>hello</p>'], 'params' => ['html' => true]]);
ok('format() keeps the html body', ($html['html'] ?? '') === '<p>hello</p>');
ok(
	'format() converts the text body from html',
	trim($html['body']) === 'hello',
	var_export($html['body'] ?? null, true),
);

ok(
	'CfwImageToolkit is unavailable without the delivery capability',
	CfwImageToolkit::isAvailable() === false,
);
ok(
	'the toolkit still advertises the formats it would deliver',
	in_array('webp', CfwImageToolkit::getSupportedExtensions(), true),
);
ok(
	'deliveryUrl refuses without the capability',
	CfwImageToolkit::deliveryUrl('public://x.png', ['width' => 10]) === null,
);

// --- the stream wrapper takes over http and https ------------------------
// Registering is mandatory rather than optional: the runtime advertises http/https
// in stream_get_wrappers() but reading through the native wrapper throws a JS
// ReferenceError that PHP cannot catch, which kills the whole invocation.
echo "\nStream wrapper\n";
$registered = HttpsStreamWrapper::register();
ok(
	'register() claims http and https',
	$registered === ['http', 'https'],
	implode(',', $registered),
);
ok('the wrapper is the one PHP now resolves', in_array('https', stream_get_wrappers(), true));

// The package is the implementation, and this module supplies only the transport. A second copy of
// the wrapper used to live here and drifted from the published one; asserting the inheritance is
// what stops it growing back.
ok(
	'it EXTENDS drupflare/stream-http rather than reimplementing it',
	is_subclass_of(HttpsStreamWrapper::class, BaseHttpsStreamWrapper::class),
	get_parent_class(HttpsStreamWrapper::class) ?: 'no parent',
);
// This is a REGRESSION test for a fatal, not a style check. A zero-argument override of the
// parent's register(callable $fetch, array $schemes) is "Declaration ... must be compatible",
// raised at class load -- so the module would have died before serving a byte. php -l is blind to
// it because the incompatibility only exists once both classes are loaded together.
$signature = new ReflectionMethod(HttpsStreamWrapper::class, 'register');
ok(
	'register() stays signature-compatible with the parent it widens',
	$signature->getNumberOfRequiredParameters() === 0 && $signature->getNumberOfParameters() === 2,
	sprintf(
		'%d required of %d',
		$signature->getNumberOfRequiredParameters(),
		$signature->getNumberOfParameters(),
	),
);
// the escape hatch the widening buys: one registration through another transport
$injected = HttpsStreamWrapper::register(
	static fn(array $request): array => [
		'ok' => true,
		'status' => 200,
		'body' => 'injected:' . $request['url'],
		'headers' => [],
	],
);
ok('an injected fetch is accepted', $injected === ['http', 'https']);
ok(
	'and it is the one that answers, so the delegation is real',
	file_get_contents('https://example.test/x') === 'injected:https://example.test/x',
);
// back to the host transport, so the refusal check below measures what it means to
HttpsStreamWrapper::register();
// no network is touched: Host::call refuses before anything is fetched
ok(
	'an unfetchable URL returns FALSE rather than something plausible',
	@file_get_contents('https://example.invalid/') === false,
);
stream_wrapper_restore('http');
stream_wrapper_restore('https');
ok('the native wrappers are restored afterwards', in_array('https', stream_get_wrappers(), true));

// --- the module registers its own wrapper --------------------------------
// This is the gap that used to read "the module does not register its own stream wrapper". The
// registration point is the TOP LEVEL of drupflare.module, because ModuleHandler::loadAll()
// includes it from DrupalKernel::preHandle() before anything routes and there is no hook that
// early. So the assertion has to be about the INCLUDE, and it only has teeth if the schemes are
// unregistered first -- a wrapper that was already there proves nothing.
echo "\nSelf-registration\n";
require_once $module . '/drupflare.install';
ok('drupflare.install defines hook_install', function_exists('drupflare_install'));
ok('drupflare.install defines hook_uninstall', function_exists('drupflare_uninstall'));
// Drupal 11.3 deprecates a procedural <module>_requirements() with no #[LegacyRequirementsHook]
// attribute (HookCollectorPass.php:639), so the two phases live in classes instead
ok(
	'and no legacy _requirements() function, which 11.3 deprecates',
	!function_exists('drupflare_requirements'),
);

stream_wrapper_unregister('http');
stream_wrapper_unregister('https');
ok(
	'CONTROL: neither scheme is registered before the module file is included',
	!in_array('http', stream_get_wrappers(), true) &&
		!in_array('https', stream_get_wrappers(), true),
);
require $module . '/drupflare.module';
ok('including drupflare.module registers https', in_array('https', stream_get_wrappers(), true));
ok('and http', in_array('http', stream_get_wrappers(), true));

// the request that ENABLES a module built its module list before the module was on it, so the
// include never ran; hook_install is the one place that covers that request
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');
drupflare_install();
ok(
	'drupflare_install() registers the wrapper for the enabling request',
	in_array('https', stream_get_wrappers(), true),
);

drupflare_uninstall();
ok('drupflare_uninstall() hands the schemes back', !in_array('https', stream_get_wrappers(), true));

// --- the requirements say so loudly --------------------------------------
echo "\nRequirements\n";
$install = DrupflareRequirements::getRequirements();
ok('install requirements report the host runtime', isset($install['drupflare_host']));
ok(
	'installing with no host capabilities is a WARNING',
	($install['drupflare_host']['severity'] ?? null) === RequirementSeverity::Warning,
);
// the control that matters: Error would make the module uninstallable on a plain PHP host,
// which is exactly where somebody would install it to read the code
ok(
	'CONTROL: it is not Error, so a plain PHP host can still install the module',
	($install['drupflare_host']['severity'] ?? null) !== RequirementSeverity::Error,
);
ok(
	'the install-time check reuses the runtime capability list rather than repeating it',
	str_contains(
		(string) file_get_contents($module . '/src/Install/Requirements/DrupflareRequirements.php'),
		'Requirements::CAPABILITIES',
	),
);

$hookAttributes = (new ReflectionMethod(Requirements::class, 'runtimeRequirements'))->getAttributes(
	Hook::class,
);
ok('runtimeRequirements() carries a Hook attribute', count($hookAttributes) === 1);
ok(
	'and it implements hook_runtime_requirements',
	count($hookAttributes) === 1 &&
		$hookAttributes[0]->newInstance()->hook === 'runtime_requirements',
);

$requirements = new Requirements();
$unregistered = $requirements->runtimeRequirements();
ok(
	'an unregistered https is an ERROR on the status report',
	($unregistered['drupflare_stream_wrapper']['severity'] ?? null) === RequirementSeverity::Error,
);
stream_wrapper_restore('http');
stream_wrapper_restore('https');
ok(
	'the native wrappers are restored after the self-registration checks',
	in_array('https', stream_get_wrappers(), true),
);

$present = $requirements->runtimeRequirements();
ok(
	'CONTROL: with the schemes registered the same entry is OK',
	($present['drupflare_stream_wrapper']['severity'] ?? null) === RequirementSeverity::OK,
);
ok(
	'the capability entry warns when the host installed none of them',
	($present['drupflare_capabilities']['severity'] ?? null) === RequirementSeverity::Warning,
);
// read through getArguments() rather than casting: rendering a TranslatableMarkup needs the
// string_translation service, and this harness has no container
ok(
	'and names what it looked for, so an absent capability is diagnosable',
	str_contains(
		(string) ($present['drupflare_capabilities']['description']->getArguments()['@absent'] ??
			''),
		'cfwMail',
	),
);
// A ROW EITHER WAY, and this used to assert the opposite. With the entry only inside the available
// branch, a deployment without the file capability got no row at all -- uploads land in MEMFS and
// vanish on eviction, reported nowhere. The absent case is the one that most needs saying, so it is
// an Error row rather than a missing one
ok(
	'the durable-file entry is present when the capability is absent',
	isset($present['drupflare_file_wrapper']),
);
ok(
	'and reports it as an error rather than an omission',
	($present['drupflare_file_wrapper']['severity'] ?? null) === RequirementSeverity::Error,
);
ok(
	'and names the consequence, which nothing else reports',
	str_contains(
		$present['drupflare_file_wrapper']['description']->getUntranslatedString(),
		'lost when this object is evicted',
	),
);

// #region router dump fingerprint

/**
 * The dumper that stops a rebuild rewriting the rows already in the table.
 *
 * A rebuild is 419 routes against three indexes -- 2,095 charged rows -- written whether or not the
 * collection changed, and `ModuleInstaller::doInstall()` rebuilds the container per module, which
 * resets `RouteProviderLazyBuilder::$rebuilt` and re-arms the trigger. The fingerprint is what
 * makes the repeat free; these assert the properties it has to have to be safe.
 */
$fingerprint = new ReflectionMethod(CfwMatcherDumper::class, 'fingerprint');
// no setAccessible: it is deprecated on 8.5 and has had no effect since 8.1
$fp = static fn(RouteCollection $c): string => $fingerprint->invoke(null, $c);

$collection = static function (array $routes): RouteCollection {
	$c = new RouteCollection();
	foreach ($routes as $name => $path) {
		$c->add($name, new Route($path));
	}
	return $c;
};

ok(
	'CfwMatcherDumper loads against real Drupal and extends the core dumper',
	is_a(CfwMatcherDumper::class, MatcherDumper::class, true),
);
ok(
	'the same collection fingerprints identically, which is what makes a repeat skippable',
	$fp($collection(['a' => '/a', 'b' => '/b'])) === $fp($collection(['a' => '/a', 'b' => '/b'])),
);
ok(
	'ITERATION ORDER does not change it, or a reordered rebuild would read as a change',
	$fp($collection(['a' => '/a', 'b' => '/b'])) === $fp($collection(['b' => '/b', 'a' => '/a'])),
);
ok(
	'CONTROL: a changed PATH does change it, so a real rebuild is never skipped',
	$fp($collection(['a' => '/a'])) !== $fp($collection(['a' => '/moved'])),
);
ok(
	'CONTROL: an added route changes it',
	$fp($collection(['a' => '/a'])) !== $fp($collection(['a' => '/a', 'b' => '/b'])),
);
ok(
	'CONTROL: a removed route changes it',
	$fp($collection(['a' => '/a', 'b' => '/b'])) !== $fp($collection(['a' => '/a'])),
);

$withAlias = $collection(['a' => '/a']);
$withAlias->addAlias('legacy', 'a');
ok(
	'CONTROL: an alias changes it, because aliases are dumped as rows too',
	$fp($collection(['a' => '/a'])) !== $fp($withAlias),
);

$requirement = new RouteCollection();
$requirement->add('a', new Route('/a', [], ['id' => '\\d+']));
ok(
	'CONTROL: a requirement that only affects compilation changes it',
	$fp($collection(['a' => '/a'])) !== $fp($requirement),
);
// #endregion
// #region cache bin indexes

/**
 * The bin schema that stops paying for indexes nothing reads.
 *
 * Every index is a charged row per insert on Durable Object billing, and rows written binds the
 * regeneration ceiling. The host GCs `cache_data` alone, so the other bins carry two indexes for no
 * reader. A pack edit alone would be undone by `ensureBinExists()`, which recreates a missing bin
 * from `schemaDefinition()`; overriding the schema is what makes it durable.
 */
$schemaOf = static function (string $bin): array {
	$backend = (new ReflectionClass(CfwCacheBackend::class))->newInstanceWithoutConstructor();
	$prop = new ReflectionProperty(CfwCacheBackend::class, 'bin');
	$prop->setValue($backend, $bin);
	return $backend->schemaDefinition();
};

ok(
	'CfwCacheBackend loads against real Drupal and extends the core backend',
	is_a(CfwCacheBackend::class, DatabaseBackend::class, true),
);
ok(
	'CfwCacheBackendFactory extends the core factory, so max-rows settings still apply',
	is_a(CfwCacheBackendFactory::class, DatabaseBackendFactory::class, true),
);

$render = $schemaOf('cache_render');
ok('an ordinary bin ships no expire index', !isset($render['indexes']['expire']));
ok('an ordinary bin ships no created index', !isset($render['indexes']['created']));
ok(
	'and keeps its primary key, which is the row lookup every read uses',
	($render['primary key'] ?? null) === ['cid'],
);
ok(
	'and keeps every FIELD, so only the indexes changed',
	array_keys($render['fields']) ===
		array_keys(
			(new ReflectionClass(DatabaseBackend::class))
				->newInstanceWithoutConstructor()
				->schemaDefinition()['fields'],
		),
);

$data = $schemaOf('cache_data');
ok(
	'CONTROL: cache_data KEEPS expire, because gcPass() deletes on it',
	isset($data['indexes']['expire']),
);
ok(
	'CONTROL: cache_data KEEPS created, because gcPass() caps rows ordering by it',
	isset($data['indexes']['created']),
);
ok(
	'the kept list names cache_data and nothing else',
	CfwCacheBackend::INDEXED_BINS === ['cache_data'],
);

// #endregion
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
