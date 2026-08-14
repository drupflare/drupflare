<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use Drupal\drupflare\Host;
use Throwable;

/**
 * Digest, HMAC and random, over `crypto.subtle`.
 *
 * WHAT crypto.subtle CAN AND CANNOT DO IS THE WHOLE DESIGN. It implements SHA-1, SHA-256, SHA-384
 * and SHA-512 and nothing else -- there is no md5 and no ripemd, by specification rather than by
 * omission. So `openssl_digest($data, 'md5')` cannot be served over the platform primitive, and the
 * two possible answers are: serve it from PHP own hash extension when that is linked in, or refuse
 * by name. It is never FALSE with no explanation, because `openssl_digest()` returns FALSE for
 * "unknown algorithm" and a caller cannot tell that apart from "this runtime has no md5".
 *
 * Symmetric only. Keypair generation, asymmetric signing and PEM export are refused in
 * `ShimRegistry` and named there; crypto.subtle can generate a keypair but cannot hand out the
 * private PEM that `openssl_pkey_export()` is expected to return, so a partial implementation would
 * be worse than none.
 */
final class CryptoShim
{
	/**
	 * PHP algorithm name => the SubtleCrypto name.
	 *
	 * Crypto.subtle spells them with a hyphen and rejects anything else.
	 */
	const SUBTLE_DIGESTS = [
		'sha1' => 'SHA-1',
		'sha256' => 'SHA-256',
		'sha384' => 'SHA-384',
		'sha512' => 'SHA-512',
	];

	/**
	 * Which SubtleCrypto digest a PHP algorithm name maps to.
	 *
	 * @param string $algorithm
	 *   A PHP hash name, in any case.
	 *
	 * @return string|null
	 *   The SubtleCrypto name, or NULL when crypto.subtle cannot do it at all.
	 */
	public static function subtleName(string $algorithm): ?string
	{
		$key = strtolower(str_replace('-', '', $algorithm));
		return self::SUBTLE_DIGESTS[$key] ?? null;
	}

	/**
	 * Openssl_digest().
	 *
	 * @param string $data
	 *   The bytes to hash.
	 * @param string $algorithm
	 *   A digest name.
	 * @param bool $binary
	 *   TRUE for raw bytes, FALSE for lowercase hex, matching openssl_digest().
	 *
	 * @return string
	 *   The digest. Never FALSE: a failure throws so it cannot be read as a value.
	 *
	 * @throws ShimRefusal
	 *   When neither crypto.subtle nor the hash extension can serve the algorithm.
	 */
	public static function digest(string $data, string $algorithm, bool $binary = false): string
	{
		ShimRegistry::assertRouted('openssl_digest');
		$subtle = self::subtleName($algorithm);

		if ($subtle !== null && Host::has('cfwDigest')) {
			$result = Host::call('cfwDigest', ['algorithm' => $subtle, 'data' => $data]);
			$hex = self::hexFrom($result, 'openssl_digest', $subtle);
			return $binary ? (string) hex2bin($hex) : $hex;
		}

		// no host function: PHP's own hash is correct when it is linked in, and saying which
		// engine produced a digest matters more here than saving a branch
		if (self::hashAvailable($algorithm)) {
			return hash($algorithm, $data, $binary);
		}

		throw new ShimRefusal(
			'openssl_digest',
			$subtle === null
				? sprintf(
					"crypto.subtle implements only %s, so '%s' cannot be computed on the platform primitive, and the hash extension is not linked into this build either.",
					implode(', ', self::SUBTLE_DIGESTS),
					$algorithm,
				)
				: sprintf(
					"'%s' is a crypto.subtle digest, but the host does not expose cfwDigest and the hash extension is not linked into this build.",
					$algorithm,
				),
			'sha256 through hash(), on a build that links the hash extension',
		);
	}

	/**
	 * Hash_hmac().
	 *
	 * @param string $algorithm
	 *   A digest name.
	 * @param string $data
	 *   The message.
	 * @param string $key
	 *   The shared secret.
	 * @param bool $binary
	 *   TRUE for raw bytes, FALSE for lowercase hex.
	 *
	 * @return string
	 *   The MAC. Never FALSE.
	 *
	 * @throws ShimRefusal
	 *   When the algorithm cannot be served, or when the key is empty.
	 */
	public static function hmac(
		string $algorithm,
		string $data,
		string $key,
		bool $binary = false,
	): string {
		ShimRegistry::assertRouted('hash_hmac');
		if ($key === '') {
			// an empty-key HMAC is computable and almost never intended; refusing names the
			// mistake where returning a valid-looking MAC would hide it
			throw new ShimRefusal(
				'hash_hmac',
				'the key is empty, which produces a MAC anyone can forge.',
				'a key from a secret binding',
			);
		}
		$subtle = self::subtleName($algorithm);

		if ($subtle !== null && Host::has('cfwHmac')) {
			$result = Host::call('cfwHmac', [
				'algorithm' => $subtle,
				'data' => $data,
				'key' => $key,
			]);
			$hex = self::hexFrom($result, 'hash_hmac', $subtle);
			return $binary ? (string) hex2bin($hex) : $hex;
		}

		if (self::hashAvailable($algorithm) && function_exists('hash_hmac')) {
			return hash_hmac($algorithm, $data, $key, $binary);
		}

		throw new ShimRefusal(
			'hash_hmac',
			sprintf(
				"neither the host's cfwHmac nor a linked hash extension can compute '%s' here.",
				$algorithm,
			),
			'sha256, on a build that links the hash extension',
		);
	}

	/**
	 * Random_bytes().
	 *
	 * The one function with a genuinely correct in-wasm fallback: `/dev/urandom` is present in MEMFS
	 * and PHP's own `random_bytes()` reads it. That is why this prefers the host but does not refuse
	 * without it -- and why dropping that file descriptor across a heap restore throws
	 * `RandomException` rather than quietly returning predictable bytes.
	 *
	 * @param int $length
	 *   How many bytes.
	 *
	 * @return string
	 *   Exactly $length bytes.
	 *
	 * @throws ShimRefusal
	 *   When the length is not positive, or when no CSPRNG answered.
	 */
	public static function randomBytes(int $length): string
	{
		ShimRegistry::assertRouted('random_bytes');
		if ($length < 1) {
			throw new ShimRefusal(
				'random_bytes',
				sprintf('%d is not a length; random_bytes() requires at least 1.', $length),
				'a positive length',
			);
		}

		if (Host::has('cfwRandom')) {
			$result = Host::call('cfwRandom', ['length' => $length]);
			$hex = self::hexFrom($result, 'random_bytes', 'crypto.getRandomValues');
			$bytes = (string) hex2bin($hex);
			// a short read is a weaker key than the caller asked for, so it is refused
			if (strlen($bytes) === $length) {
				return $bytes;
			}
			throw new ShimRefusal(
				'random_bytes',
				sprintf(
					'the host returned %d bytes for a %d-byte request, and padding it would weaken a key silently.',
					strlen($bytes),
					$length,
				),
			);
		}

		try {
			return random_bytes($length);
		} catch (Throwable $e) {
			// /dev/urandom's descriptor is the usual cause; see the heap-restore fd contract
			throw new ShimRefusal(
				'random_bytes',
				sprintf(
					'no CSPRNG answered: the host exposes no cfwRandom and the in-wasm source failed with %s.',
					get_class($e),
				),
			);
		}
	}

	/**
	 * Whether PHP's own hash() can do this algorithm in this build.
	 *
	 * @param string $algorithm
	 *   A PHP hash name.
	 *
	 * @return bool
	 *   TRUE when hash() exists and lists it.
	 */
	public static function hashAvailable(string $algorithm): bool
	{
		if (!function_exists('hash') || !function_exists('hash_algos')) {
			return false;
		}
		return in_array(strtolower($algorithm), hash_algos(), true);
	}

	/**
	 * Reads a hex digest out of a host result, refusing anything that is not one.
	 *
	 * A host bridge that answered with `ok => false`, or with a truncated or non-hex string, must not
	 * be allowed to become a digest -- a wrong digest is accepted by every caller and detected by
	 * none of them.
	 */
	private static function hexFrom(array $result, string $function, string $via): string
	{
		if (($result['ok'] ?? false) !== true) {
			throw new ShimRefusal(
				$function,
				sprintf(
					'the host bridge to %s failed: %s',
					$via,
					(string) ($result['error'] ?? 'no reason given'),
				),
			);
		}
		$hex = (string) ($result['hex'] ?? '');
		if ($hex === '' || preg_match('/^[0-9a-f]+$/i', $hex) !== 1 || strlen($hex) % 2 !== 0) {
			throw new ShimRefusal(
				$function,
				sprintf(
					'the host bridge to %s returned %s, which is not a hex digest.',
					$via,
					$hex === '' ? 'nothing' : 'a malformed value',
				),
			);
		}
		return strtolower($hex);
	}
}
