<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

/**
 * Every C-library function this runtime is asked for, and what happens to it.
 *
 * Three verdicts and no fourth. A function is ROUTED over a platform primitive, REFUSED with a
 * named reason, or -- if it is not in this table at all -- refused anyway, because an unlisted
 * function is one nobody has thought about and guessing is how a wrong answer ships.
 */
final class ShimRegistry
{
	/**
	 * Routed over a Cloudflare primitive.
	 */
	const ROUTE = 'route';

	/**
	 * Refused, with a reason.
	 */
	const REFUSE = 'refuse';

	/**
	 * Works as the manual describes it, with no shim in the way.
	 *
	 * Listed rather than omitted because absence answers nothing, and the entries here are the ones
	 * a reader would otherwise assume are gone: `getimagesize()` reads headers in ext/standard and
	 * survives gd's absence, which this table asserted the opposite of.
	 */
	const NATIVE = 'native';

	/**
	 * The table.
	 *
	 * @return array
	 *   Keyed by function name. Each value has: verdict, via (the primitive or the empty string),
	 *   why (always a sentence), and alternative (what to use instead, or the empty string).
	 */
	public static function functions(): array
	{
		return [
			// #region routed over CfwDeferredHttp
			'curl_init' => [
				'verdict' => self::ROUTE,
				'via' => 'CfwDeferredHttp',
				'why' => 'A handle is a local array here; nothing opens until curl_exec().',
				'alternative' => '',
			],
			'curl_setopt' => [
				'verdict' => self::ROUTE,
				'via' => 'CfwDeferredHttp',
				'why' => 'The URL, method, headers and body options map onto a PSR-7 request.',
				'alternative' => '',
			],
			'curl_setopt_array' => [
				'verdict' => self::ROUTE,
				'via' => 'CfwDeferredHttp',
				'why' =>
					'Applies curl_setopt() once per entry, and refuses the whole array if any option is.',
				'alternative' => '',
			],
			'curl_exec' => [
				'verdict' => self::ROUTE,
				'via' => 'CfwDeferredHttp',
				'why' =>
					'Answers from the HTTP cache when a previous fetch left a body, otherwise queues and reports a 202.',
				'alternative' => '',
			],
			'curl_getinfo' => [
				'verdict' => self::ROUTE,
				'via' => 'CfwDeferredHttp',
				'why' => 'Reports what the handle actually did, including the deferred 202.',
				'alternative' => '',
			],
			'curl_close' => [
				'verdict' => self::ROUTE,
				'via' => 'CfwDeferredHttp',
				'why' => 'Frees the handle array; there was never a socket to close.',
				'alternative' => '',
			],
			'curl_errno' => [
				'verdict' => self::ROUTE,
				'via' => 'CfwDeferredHttp',
				'why' => 'Zero, or CURLE_COULDNT_CONNECT when the queue itself refused.',
				'alternative' => '',
			],
			'curl_error' => [
				'verdict' => self::ROUTE,
				'via' => 'CfwDeferredHttp',
				'why' => 'The queue error text, never an empty string standing in for success.',
				'alternative' => '',
			],
			// #endregion
			// #region routed over crypto.subtle
			'openssl_digest' => [
				'verdict' => self::ROUTE,
				'via' => 'crypto.subtle.digest',
				'why' =>
					'SHA-1, SHA-256, SHA-384 and SHA-512 only; crypto.subtle implements no others.',
				'alternative' => '',
			],
			'hash_hmac' => [
				'verdict' => self::ROUTE,
				'via' => 'crypto.subtle.sign',
				'why' => 'HMAC over the same four digests, keyed through importKey().',
				'alternative' => '',
			],
			'random_bytes' => [
				'verdict' => self::ROUTE,
				'via' => 'crypto.getRandomValues',
				'why' => 'The host CSPRNG, with /dev/urandom as the in-wasm fallback.',
				'alternative' => '',
			],
			// #endregion
			// #region refused, and this half is the important half
			'openssl_pkey_new' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'Keypair generation needs a real OpenSSL; crypto.subtle can generate a key but cannot hand out the PEM this returns.',
				'alternative' => 'a key minted outside the Worker and read from a secret binding',
			],
			'openssl_pkey_export' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'There is no private key object here to export, because none can be generated.',
				'alternative' => 'a key minted outside the Worker and read from a secret binding',
			],
			'openssl_csr_new' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'A CSR needs a private key this runtime cannot produce.',
				'alternative' => 'a key minted outside the Worker and read from a secret binding',
			],
			'openssl_sign' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'createSign() is synchronous in workerd, so a 2048-bit RS256 signature returns in-line; crypto.subtle being async was never the constraint.',
				'alternative' => '',
			],
			'openssl_verify' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'createVerify(), same seam as openssl_sign(); the 1/0/-1 tri-state is preserved, and -1 means the call failed rather than the signature being wrong.',
				'alternative' => '',
			],
			'openssl_private_encrypt' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'Only signing and verification are bridged; raw private-key encryption has no caller and an unused surface is worse than an absent one.',
				'alternative' => 'openssl_sign() when the goal is authenticity rather than secrecy',
			],
			'imagecreatetruecolor' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'gd is not compiled in: it measured 684,821 bytes against a 3 MB gzipped bundle ceiling, so images are resized at delivery instead.',
				'alternative' => 'CfwImageToolkit, which rewrites the URL and lets the edge resize',
			],
			'imagecreatefromstring' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'gd is not compiled in; see imagecreatetruecolor().',
				'alternative' => 'CfwImageToolkit, which rewrites the URL and lets the edge resize',
			],
			'imagejpeg' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'gd is not compiled in; see imagecreatetruecolor().',
				'alternative' => 'CfwImageToolkit, which rewrites the URL and lets the edge resize',
			],
			'imagepng' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'gd is not compiled in; see imagecreatetruecolor().',
				'alternative' => 'CfwImageToolkit, which rewrites the URL and lets the edge resize',
			],
			'getimagesize' => [
				'verdict' => self::NATIVE,
				'via' => '',
				'why' =>
					'Parses image headers in ext/standard and never went through gd or libjpeg, so it works here; CfwImageToolkit reads its dimensions from it.',
				'alternative' => '',
			],
			'exec' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'There is no shell and no process table in a Worker; nothing can be executed.',
				'alternative' => 'OpsRegistry, which is why this project does not ship Drush',
			],
			'shell_exec' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'There is no shell; see exec().',
				'alternative' => 'OpsRegistry, which is why this project does not ship Drush',
			],
			'system' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'There is no shell; see exec().',
				'alternative' => 'OpsRegistry, which is why this project does not ship Drush',
			],
			'passthru' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'There is no shell; see exec().',
				'alternative' => 'OpsRegistry, which is why this project does not ship Drush',
			],
			'proc_open' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'A Worker cannot fork, so there is no child process to attach pipes to.',
				'alternative' => 'OpsRegistry, which is why this project does not ship Drush',
			],
			'popen' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'A Worker cannot fork; see proc_open().',
				'alternative' => 'OpsRegistry, which is why this project does not ship Drush',
			],
			'fsockopen' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'There are no raw sockets. Outbound traffic goes through fetch(), which is request-shaped and cannot be a stream.',
				'alternative' =>
					'the https:// stream wrapper, or CfwDeferredHttp for a full request',
			],
			'pfsockopen' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'There are no raw sockets, and nothing persists between invocations either.',
				'alternative' =>
					'the https:// stream wrapper, or CfwDeferredHttp for a full request',
			],
			'stream_socket_client' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'There are no raw sockets; see fsockopen().',
				'alternative' =>
					'the https:// stream wrapper, or CfwDeferredHttp for a full request',
			],
			// #endregion
		];
	}

	/**
	 * Whether this function is in the table at all.
	 *
	 * @param string $function
	 *   Function name, without parentheses.
	 *
	 * @return bool
	 *   TRUE when it is declared either way.
	 */
	public static function has(string $function): bool
	{
		return array_key_exists($function, self::functions());
	}

	/**
	 * The verdict for a function.
	 *
	 * Fails CLOSED: an unlisted function is REFUSE, because a function nobody has classified is one
	 * nobody has checked, and the alternative is guessing on behalf of a caller who will read
	 * whatever comes back as real.
	 *
	 * @param string $function
	 *   Function name.
	 *
	 * @return string
	 *   self::ROUTE or self::REFUSE.
	 */
	public static function verdict(string $function): string
	{
		$entry = self::functions()[$function] ?? null;
		if ($entry === null) {
			return self::REFUSE;
		}
		return (string) $entry['verdict'];
	}

	/**
	 * Whether calling this would be refused.
	 *
	 * @param string $function
	 *   Function name.
	 *
	 * @return bool
	 *   TRUE when refused, including for an unknown name.
	 */
	public static function isRefused(string $function): bool
	{
		return self::verdict($function) === self::REFUSE;
	}

	/**
	 * The reason, which is never empty for any input.
	 *
	 * @param string $function
	 *   Function name.
	 *
	 * @return string
	 *   A sentence, including for a name nobody has ever heard of.
	 */
	public static function reason(string $function): string
	{
		$entry = self::functions()[$function] ?? null;
		if ($entry === null) {
			return sprintf(
				'%s is not in the shim registry, so it is refused rather than guessed at. Add it to ShimRegistry with a verdict.',
				$function,
			);
		}
		return (string) $entry['why'];
	}

	/**
	 * What to use instead, when there is something.
	 *
	 * @param string $function
	 *   Function name.
	 *
	 * @return string
	 *   The alternative, or an empty string.
	 */
	public static function alternative(string $function): string
	{
		$entry = self::functions()[$function] ?? null;
		if ($entry === null) {
			return '';
		}
		return (string) $entry['alternative'];
	}

	/**
	 * The primitive a routed function runs over.
	 *
	 * @param string $function
	 *   Function name.
	 *
	 * @return string
	 *   The primitive, or an empty string for anything refused.
	 */
	public static function via(string $function): string
	{
		$entry = self::functions()[$function] ?? null;
		if ($entry === null) {
			return '';
		}
		return (string) $entry['via'];
	}

	/**
	 * Builds the refusal for a function, so every caller words it identically.
	 *
	 * @param string $function
	 *   Function name.
	 *
	 * @return ShimRefusal
	 *   Ready to throw.
	 */
	public static function refusal(string $function): ShimRefusal
	{
		return new ShimRefusal($function, self::reason($function), self::alternative($function));
	}

	/**
	 * Refuses unless the function is routed.
	 *
	 * The one-line guard every shim entry point starts with.
	 *
	 * @param string $function
	 *   Function name.
	 *
	 * @throws ShimRefusal
	 *   When the function is refused or unknown.
	 */
	public static function assertRouted(string $function): void
	{
		if (self::isRefused($function)) {
			throw self::refusal($function);
		}
	}

	/**
	 * Every refused name.
	 *
	 * @return string[]
	 *   Sorted, so a diff of this list is readable.
	 */
	public static function refused(): array
	{
		$out = [];
		foreach (self::functions() as $name => $entry) {
			if ($entry['verdict'] === self::REFUSE) {
				$out[] = $name;
			}
		}
		sort($out);
		return $out;
	}

	/**
	 * Every routed name.
	 *
	 * @return string[]
	 *   Sorted.
	 */
	public static function routed(): array
	{
		$out = [];
		foreach (self::functions() as $name => $entry) {
			if ($entry['verdict'] === self::ROUTE) {
				$out[] = $name;
			}
		}
		sort($out);
		return $out;
	}

	/**
	 * Every name that works untouched.
	 *
	 * @return string[]
	 *   Sorted, so a diff of this list is readable.
	 */
	public static function native(): array
	{
		$out = [];
		foreach (self::functions() as $name => $entry) {
			if ($entry['verdict'] === self::NATIVE) {
				$out[] = $name;
			}
		}
		sort($out);
		return $out;
	}

	/**
	 * Every verdict a caller may see.
	 *
	 * @return string[]
	 *   In the order a reader should think about them: works, works through us, does not work.
	 */
	public static function verdicts(): array
	{
		return [self::NATIVE, self::ROUTE, self::REFUSE];
	}
}
