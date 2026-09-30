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
				'why' =>
					'The URL, method, headers, body, timeout and callback options map onto a PSR-7 request; turning certificate verification off or naming a proxy is refused.',
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
			'curl_reset' => [
				'verdict' => self::ROUTE,
				'via' => 'CfwDeferredHttp',
				'why' =>
					'Returns the handle array to its defaults; there is no connection to keep.',
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
			// #region the crypto three, and all three verdicts here were wrong
			//
			// They said ROUTE over crypto.subtle, and the route does not exist: `CryptoShim` is the
			// implementation and the worker installs no `cfwDigest`, `cfwHmac` or `cfwRandom`, so
			// nothing was ever routed anywhere. Two of them are PHP builtins and worked regardless,
			// which is why nobody noticed. `openssl_digest` is NOT -- the shipping binary is built
			// `WITH_OPENSSL=0` -- so a caller trusting this table got
			// `Call to undefined function openssl_digest()` from the one place whose stated job is
			// that "an unlisted function is one nobody has thought about".
			'openssl_digest' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'The binary is built WITH_OPENSSL=0, so this function does not exist at all.',
				'alternative' => 'hash(), which is ext/standard and covers the same digests.',
			],
			'hash_hmac' => [
				'verdict' => self::NATIVE,
				'via' => '',
				'why' => 'ext/hash is compiled in, so this is the real function.',
				'alternative' => '',
			],
			'random_bytes' => [
				'verdict' => self::NATIVE,
				'via' => '',
				'why' => 'ext/random is compiled in and reads the host CSPRNG through emscripten.',
				'alternative' => '',
			],
			// #endregion
			// #region refused, and this half is the important half
			'openssl_pkey_new' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'generateKeyPairSync() is synchronous in workerd and returns the PEM directly; the earlier refusal said crypto.subtle could not hand out a PEM, which was true of the wrong API.',
				'alternative' => '',
			],
			'openssl_pkey_export' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'createPrivateKey()->export(); a passphrase is refused rather than ignored, because answering with an unencrypted key to a caller who asked for an encrypted one hands them a secret they believe is protected.',
				'alternative' => '',
			],
			'openssl_pkey_get_public' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'createPublicKey() takes a PEM, a certificate or a JWK and exports SPKI PEM, which is what openssl_verify() accepts. This is the step every OIDC library needs between a key set and a verification.',
				'alternative' => '',
			],
			'openssl_csr_new' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'node:crypto has no certificate-request primitive at all, so this is absent rather than unimplemented. It is the only one of the four that has nothing behind it.',
				'alternative' => 'a CSR produced outside the Worker',
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
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'privateEncrypt(), measured returning 256 bytes synchronously. Paired with openssl_public_decrypt(), which is the shape a legacy licence check or an older SSO handshake uses.',
				'alternative' => '',
			],
			'openssl_public_decrypt' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' => 'publicDecrypt(), the other half of openssl_private_encrypt().',
				'alternative' => '',
			],
			'openssl_encrypt' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'createCipheriv() for AES CBC, CTR, ECB and GCM, synchronous; key and IV are sized the way ext-openssl sizes them, and the GCM tag comes back by reference.',
				'alternative' => '',
			],
			'openssl_decrypt' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' => 'createDecipheriv(); a GCM tag that does not authenticate answers FALSE.',
				'alternative' => '',
			],
			'openssl_pkey_get_private' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'createPrivateKey(), which also decrypts a passphrase-protected PEM; returns an OpenSSLAsymmetricKey stand-in holding the PEM.',
				'alternative' => '',
			],
			'openssl_pkey_get_details' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'KeyObject details plus a JWK export: bits, the public PEM, the key type and the RSA or EC parameters.',
				'alternative' => '',
			],
			'openssl_pkey_derive' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'createECDH() over the raw scalar and point; workerd\'s diffieHellman() refuses EC keys.',
				'alternative' => '',
			],
			'openssl_public_encrypt' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' => 'publicEncrypt() with PKCS#1 v1.5 or OAEP padding.',
				'alternative' => '',
			],
			'openssl_private_decrypt' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' => 'privateDecrypt() with PKCS#1 v1.5 or OAEP padding.',
				'alternative' => '',
			],
			'openssl_x509_read' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'X509Certificate, returning an OpenSSLCertificate stand-in holding the PEM.',
				'alternative' => '',
			],
			'openssl_x509_parse' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' =>
					'X509Certificate\'s subject, issuer, serial, validity and subjectAltName, under ext-openssl\'s key names.',
				'alternative' => '',
			],
			'openssl_x509_fingerprint' => [
				'verdict' => self::ROUTE,
				'via' => 'node:crypto',
				'why' => 'A digest of the certificate\'s DER bytes.',
				'alternative' => '',
			],
			'openssl_x509_checkpurpose' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'There is no trust store or purpose table here; the shim answers -1 and records a degradation.',
				'alternative' =>
					'openssl_verify() against the issuer key the caller already trusts',
			],
			// #region gd, queued and run by the host
			...self::gdRows(),
			// #endregion
			'getimagesize' => [
				'verdict' => self::NATIVE,
				'via' => '',
				'why' =>
					'Parses image headers in ext/standard and never went through gd or libjpeg, so it works here; CfwImageToolkit reads its dimensions from it.',
				'alternative' => '',
			],
			'exif_read_data' => [
				'verdict' => self::ROUTE,
				'via' => 'Exif',
				'why' =>
					'Drupal\\drupflare\\Shim\\Exif reads IFD0 and the Exif sub-IFD of a JPEG or TIFF in PHP; ext-exif is not compiled in.',
				'alternative' => '',
			],
			'transliterator_transliterate' => [
				'verdict' => self::ROUTE,
				'via' => 'PhpTransliteration',
				'why' =>
					'Drupal\\drupflare\\Shim\\Transliterator serves Any-Latin; Latin-ASCII over core\'s transliteration tables; any other rule is refused by name.',
				'alternative' => '',
			],
			'finfo_open' => [
				'verdict' => self::ROUTE,
				'via' => 'Finfo',
				'why' =>
					'Drupal\\drupflare\\Shim\\Finfo detects the MIME type from magic bytes; ext-fileinfo is not compiled in.',
				'alternative' => '',
			],
			'mime_content_type' => [
				'verdict' => self::ROUTE,
				'via' => 'Finfo',
				'why' => 'Answered by the same magic-byte table as finfo_open().',
				'alternative' => '',
			],
			'sleep' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'The clock does not advance inside a PHP run, so a wait was a spin billed as CPU; the call now returns at once and records a degradation.',
				'alternative' => 'nothing; a retry loop still works, it just does not pause',
			],
			'usleep' => [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' => 'Returns at once and records a degradation; see sleep().',
				'alternative' => 'nothing; a retry loop still works, it just does not pause',
			],
			'exec' => [
				'verdict' => self::ROUTE,
				'via' => 'Exec\\Router',
				'why' =>
					'There is no shell and no process table; a fixed table of programs is served in-process, and any other line fails as a failed launch (false, exit 127) with a degradation recorded.',
				'alternative' => '',
			],
			'shell_exec' => [
				'verdict' => self::ROUTE,
				'via' => 'Exec\\Router',
				'why' => 'Served by the same router as exec().',
				'alternative' => '',
			],
			'system' => [
				'verdict' => self::ROUTE,
				'via' => 'Exec\\Router',
				'why' => 'Served by the same router as exec().',
				'alternative' => '',
			],
			'passthru' => [
				'verdict' => self::ROUTE,
				'via' => 'Exec\\Router',
				'why' => 'Served by the same router as exec().',
				'alternative' => '',
			],
			'proc_open' => [
				'verdict' => self::ROUTE,
				'via' => 'Exec\\Router',
				'why' =>
					'Returns pipes that carry the served program\'s stdout and stderr, and proc_get_status() and proc_close() report its exit code, which is what Symfony Process needs; a pty is refused.',
				'alternative' => '',
			],
			'popen' => [
				'verdict' => self::ROUTE,
				'via' => 'Exec\\Router',
				'why' =>
					'A read handle over the served program\'s output; a write handle is refused.',
				'alternative' => '',
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
	 * The gd functions: routed ones queue on a handle and run in the host, the rest are refused.
	 *
	 * @return array
	 *   Rows in the shape {@see self::functions()} returns.
	 */
	private static function gdRows(): array
	{
		$routed = [
			'imagecreatefromstring' => 'Decodes the header only; the source stays encoded.',
			'imagecreatefromjpeg' => 'Reads the file and keeps the encoded bytes.',
			'imagecreatefrompng' => 'Reads the file and keeps the encoded bytes.',
			'imagecreatefromwebp' => 'Reads the file and keeps the encoded bytes.',
			'imagecreatefromgif' => 'Reads the file and keeps the encoded bytes.',
			'imagecreatetruecolor' =>
				'A blank canvas of the given size; a copy onto all of it fills it.',
			'imagesx' => 'Reads the tracked width.',
			'imagesy' => 'Reads the tracked height.',
			'imagedestroy' => 'A no-op, as in PHP 8.',
			'imagealphablending' => 'Stored on the handle.',
			'imagesavealpha' =>
				'Stored on the handle; a PNG written without it records a degradation.',
			'imagecopyresampled' =>
				'Queues a crop and a resize when the copy covers a fresh canvas; any other copy is refused.',
			'imagecopyresized' => 'As imagecopyresampled(), with the nearest filter.',
			'imagescale' => 'Queues a resize on a copy of the handle.',
			'imagecrop' =>
				'Queues a crop inside the image bounds; a rectangle outside them is refused.',
			'imagerotate' => 'Queues a rotation, with the corner fill passed through.',
			'imagejpeg' => 'Parks on cfwpark+image:// and the host encodes.',
			'imagepng' => 'Parks on cfwpark+image:// and the host encodes.',
			'imagewebp' => 'Parks on cfwpark+image:// and the host encodes.',
			'imagegif' => 'Parks on cfwpark+image:// and the host encodes.',
		];
		$rows = [];
		foreach ($routed as $name => $why) {
			$rows[$name] = [
				'verdict' => self::ROUTE,
				'via' => 'Shim\\Gd',
				'why' => $why,
				'alternative' => '',
			];
		}
		foreach (
			[
				'imagesetpixel',
				'imagecolorallocate',
				'imagecolorat',
				'imagefilledrectangle',
				'imagefill',
				'imagecopy',
				'imagecopymerge',
			]
			as $name
		) {
			$rows[$name] = [
				'verdict' => self::REFUSE,
				'via' => '',
				'why' =>
					'No pixel is readable or writable from PHP: operations run in the host as a queue on the encoded image.',
				'alternative' => 'CfwImageToolkit, which rewrites the URL and lets the edge resize',
			];
		}
		return $rows;
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
