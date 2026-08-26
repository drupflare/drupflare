<?php

/**
 * @file
 * Drives CfwTcp against a scripted host, which is the only half of the TCP tier PHP owns.
 *
 * SEPARATE FROM health-suite.php for the same reason `solarium-transport.php` is: these assertions
 * need `vrzno_env()` to exist so `Host::call()` reaches a double, and defining that global in the
 * health suite would change what every other Host-touching assertion there measures.
 *
 * WHAT THIS CANNOT TEST is the exchange itself -- the socket, the RESP codec and the syslog framing
 * all live in JavaScript, and `tests/unit/ops/tcp.spec.ts` in the worker drives edgeport's real
 * client over a scripted socket. What is testable here is the CONTRACT: what PHP sends, what it
 * makes of each reply shape, and that a missing capability is declared rather than silently false.
 */

$root = __DIR__ . '/..';

// the double has to be installed BEFORE anything calls Host::fn(), which resolves through it
$GLOBALS['__cfw_host'] = [];
if (!function_exists('vrzno_env')) {
	/**
	 * Stands in for the runtime's capability table.
	 *
	 * @param string $name
	 *   The capability key.
	 *
	 * @return mixed
	 *   The registered double, or NULL when nothing is registered.
	 */
	function vrzno_env(string $name): mixed
	{
		return $GLOBALS['__cfw_host'][$name] ?? null;
	}
}

use Drupal\drupflare\Degradation;
use Drupal\drupflare\Network\CfwOidc;
use Drupal\drupflare\Network\CfwTcp;

// `CfwOidc` extends `ControllerBase`, so loading it needs drupal/core on the path. This repo's own
// vendor/ has it after a composer install; the worker's gate checks the sibling out WITHOUT one and
// keeps a full tree beside it instead, which is why there are two candidates.
foreach (
	[$root . '/vendor/autoload.php', $root . '/../../drupal-src/vendor/autoload.php']
	as $candidate
) {
	if (is_file($candidate)) {
		require_once $candidate;
		break;
	}
}

spl_autoload_register(function (string $class) use ($root): void {
	$prefix = 'Drupal\\drupflare\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$rel = str_replace('\\', '/', substr($class, strlen($prefix)));
	$file = $root . '/src/' . $rel . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});

$pass = 0;
$fail = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $label
 *   What is being asserted.
 * @param bool $condition
 *   The result.
 * @param string $detail
 *   Extra context printed on failure.
 */
function ok(string $label, bool $condition, string $detail = ''): void
{
	global $pass, $fail;
	if ($condition) {
		$pass++;
		echo "  ok   $label\n";
		return;
	}
	$fail++;
	echo "  FAIL $label" . ($detail === '' ? '' : " -- $detail") . "\n";
}

/**
 * Installs a `cfwTcp` double that records what it was sent and answers a scripted reply.
 *
 * @param array $reply
 *   What the host answers.
 *
 * @return array
 *   A one-element array holding the payloads seen, by reference through the closure.
 */
function script_host(array $reply): array
{
	$seen = new ArrayObject();
	$GLOBALS['__cfw_host']['cfwTcp'] = function (string $json) use ($seen, $reply): string {
		$seen[] = json_decode($json, true);
		return json_encode($reply);
	};
	return [$seen];
}

echo "# CfwTcp -- what PHP sends and what it makes of each reply\n";

// #region the capability is absent
$GLOBALS['__cfw_host'] = [];
Degradation::reset();

ok('a host with no cfwTcp reports the capability as unavailable', !CfwTcp::available());

$out = CfwTcp::redis(['GET', 'k']);
ok(
	'a redis call without the capability refuses rather than throwing',
	($out['ok'] ?? null) === false,
);
ok(
	'and the refusal names the capability an operator has to install',
	str_contains($out['error'] ?? '', 'cfwTcp'),
	$out['error'] ?? '(no error)',
);
ok(
	'and names the vars behind it, since that is the actionable half',
	str_contains($out['error'] ?? '', 'REDIS_URL'),
	$out['error'] ?? '(no error)',
);
ok('a missing capability is DECLARED, never silently false', Degradation::isDeclared('cfwTcp'));
ok('and syslog refuses the same way', CfwTcp::syslog('anything') === false);
// #endregion

// #region redis, answered
[$seen] = script_host(['ok' => true, 'status' => 200, 'body' => '{"a":1,"b":[2,3]}']);
Degradation::reset();

ok('the capability now reports as available', CfwTcp::available());

$out = CfwTcp::redis(['GET', 'greeting']);
ok('an answered call reports ok', ($out['ok'] ?? null) === true);
ok(
	'and DECODES the body, so a caller gets PHP values rather than a string',
	$out['value'] === ['a' => 1, 'b' => [2, 3]],
	var_export($out['value'] ?? null, true),
);
ok('an answered call is not queued', ($out['queued'] ?? null) === false);

$payload = $seen[0];
ok('the protocol is named explicitly', ($payload['protocol'] ?? null) === 'redis');
ok(
	'the command reaches the host verbatim',
	($payload['args'] ?? null) === ['GET', 'greeting'],
	json_encode($payload['args'] ?? null),
);
ok(
	'no host, port or credential is sent, because the endpoint is the operator’s',
	array_keys($payload) === ['protocol', 'args'],
	json_encode(array_keys($payload)),
);

// a sparse array would serialise as a JSON object and arrive as a map rather than a list
[$seen] = script_host(['ok' => true, 'status' => 200, 'body' => 'null']);
CfwTcp::redis([3 => 'GET', 7 => 'k']);
ok(
	'a sparse argument array is reindexed, or the host receives an object',
	($seen[0]['args'] ?? null) === ['GET', 'k'],
	json_encode($seen[0]['args'] ?? null),
);
// #endregion

// #region redis, deferred and refused
[$seen] = script_host([
	'ok' => false,
	'error' => 'GET is not in the exchange cache; queued for the next drain.',
	'queued' => true,
]);

$out = CfwTcp::redis(['GET', 'k']);
ok('a deferred call reports not-ok', ($out['ok'] ?? null) === false);
ok(
	'and says it was QUEUED, which is what makes a later read worth trying',
	($out['queued'] ?? null) === true,
);
ok('and carries the host’s own sentence', str_contains($out['error'] ?? '', 'exchange cache'));

[$seen] = script_host(['ok' => false, 'error' => 'FLUSHALL is not reachable from module code']);
$out = CfwTcp::redis(['FLUSHALL']);
ok('a refused command is not reported as queued', ($out['queued'] ?? null) === false);
ok('and the refusal reaches the caller intact', str_contains($out['error'] ?? '', 'FLUSHALL'));
// #endregion

// #region syslog
[$seen] = script_host(['ok' => true, 'queued' => 'tcp+syslog://logs:514/']);

ok('an accepted record reports true', CfwTcp::syslog('a node was saved') === true);
$payload = $seen[0];
ok('the protocol is named explicitly', ($payload['protocol'] ?? null) === 'syslog');
ok(
	'the record carries the message and a default severity',
	($payload['record'] ?? null) === ['message' => 'a node was saved', 'severity' => 'info'],
	json_encode($payload['record'] ?? null),
);

[$seen] = script_host(['ok' => true]);
CfwTcp::syslog('boom', 'err', ['facility' => 'local0', 'msgId' => 'NODE', 'bogus' => 'x']);
$record = $seen[0]['record'] ?? [];
ok('an explicit severity is used', ($record['severity'] ?? null) === 'err');
ok('facility and msgId pass through', ($record['facility'] ?? null) === 'local0');
ok('msgId passes through', ($record['msgId'] ?? null) === 'NODE');
ok(
	'and an unknown extra is DROPPED rather than forwarded',
	!array_key_exists('bogus', $record),
	json_encode($record),
);

[$seen] = script_host(['ok' => false, 'error' => 'syslog is not configured; set SYSLOG_URL']);
ok('a refused record reports false', CfwTcp::syslog('x') === false);
// #endregion

// #region CfwOidc -- the two pure decisions the controller makes
echo "\n# CfwOidc -- authmap scoping and the account name\n";

ok(
	'the authmap key is scoped by ISSUER, not by subject alone',
	CfwOidc::authname('user-42', 'https://idp.test') === 'https://idp.test|user-42',
);
ok(
	'a trailing slash on the issuer does not make a second identity',
	CfwOidc::authname('user-42', 'https://idp.test/') ===
		CfwOidc::authname('user-42', 'https://idp.test'),
);
// without issuer scoping a subject from a newly-configured provider could take over an existing
// account that happened to share the identifier
ok(
	'the same subject at two issuers is two identities',
	CfwOidc::authname('user-42', 'https://a.test') !==
		CfwOidc::authname('user-42', 'https://b.test'),
);

ok('a name claim is preferred', CfwOidc::accountName(['name' => 'Someone']) === 'Someone');
ok(
	'preferred_username is the next fallback',
	CfwOidc::accountName(['preferred_username' => 'someone']) === 'someone',
);
ok(
	'email is the last fallback',
	CfwOidc::accountName(['email' => 'someone@example.com']) === 'someone@example.com',
);
ok(
	'a whitespace-only claim is not a name',
	CfwOidc::accountName(['name' => '   ', 'email' => 'someone@example.com']) ===
		'someone@example.com',
);
ok(
	'no usable claim yields an empty string, so externalauth generates one',
	CfwOidc::accountName([]) === '',
);
// #endregion

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
