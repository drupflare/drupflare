<?php

declare(strict_types=1);

namespace Drupal\drupflare\Password;

use Drupal\Core\Password\PasswordInterface;
use Drupal\drupflare\Degradation;

/**
 * Argon2id password hashing, computed on the host rather than in PHP.
 *
 * WHY THIS IS A SERVICE AND NOT A SHIM. `password_hash()` is a built-in and is always declared, so
 * the conditional-declaration pattern the other capability shims use cannot bind here. Drupal's
 * `password` service is the supported seam, and swapping it from our own module is ordinary Drupal
 * rather than a core patch.
 *
 * WHY THE HOST AND NOT THE INTERPRETER. ext-argon2 is not in the build, and compiling it in would
 * put a 19 MiB arena inside PHP's linear memory where `memory.grow` has no inverse -- the first
 * hash would raise that object's floor for the rest of its life. A host-side arena is
 * garbage-collected. Measured on a deployed throwaway: 19 MiB of transient JS allocation coexists
 * with a 117 MiB wasm heap, ten times consecutively.
 *
 * DELEGATES RATHER THAN REPLACES. A site that has been running has bcrypt hashes in `users_field_
 * data`, and every one of them must keep working. `check()` reads the hash's own prefix and hands
 * anything that is not argon2id back to core; `needsRehash()` then asks for an upgrade, so a site
 * migrates one login at a time instead of locking everybody out.
 *
 * @see \Drupal\drupflare\Degradation
 */
final class CfwPassword implements PasswordInterface
{
	/**
	 * The prefix PHP's own argon2id encoding uses.
	 *
	 * Matched rather than assumed: a hash written here has to verify on an ordinary PHP with
	 * ext-argon2, so that a site leaving this platform does not leave every password behind.
	 */
	public const ARGON2ID_PREFIX = '$argon2id$';

	public function __construct(
		private readonly PasswordInterface $fallback,
		private readonly bool $enabled,
	) {}

	/**
	 * Whether the host bridge is present at all.
	 *
	 * Separate from `$enabled`, which is the operator's choice. Both have to be true, and the two
	 * are distinguished so the status report can say which one is missing.
	 */
	public static function bridgeAvailable(): bool
	{
		return function_exists('cfw_argon2_available');
	}

	/**
	 * {@inheritdoc}
	 */
	public function hash(#[\SensitiveParameter] $password)
	{
		if (!$this->enabled || !self::bridgeAvailable()) {
			return $this->fallback->hash($password);
		}

		$encoded = cfw_argon2_hash($password);
		if (!is_string($encoded) || $encoded === '') {
			// DECLARED rather than silent. A refused hash falling back to bcrypt is the correct
			// behaviour and is also exactly the kind of downgrade nobody notices.
			Degradation::record(
				'argon2id hashing',
				'blocked',
				'the host refused the hash; this password was stored with the fallback algorithm',
			);
			return $this->fallback->hash($password);
		}
		return $encoded;
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(#[\SensitiveParameter] $password, #[\SensitiveParameter] $hash)
	{
		if (!is_string($hash) || strncmp($hash, self::ARGON2ID_PREFIX, 10) !== 0) {
			// bcrypt, or Drupal's legacy $S$ form; core still owns both
			return $this->fallback->check($password, $hash);
		}
		if (!self::bridgeAvailable()) {
			// an argon2id hash with no host to verify it. Refusing is the only safe answer -- the
			// alternative is treating an unverifiable hash as a match
			Degradation::record(
				'argon2id verification',
				'blocked',
				'a stored argon2id hash cannot be verified because the host bridge is absent',
			);
			return false;
		}
		return cfw_argon2_verify($password, $hash) === true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function needsRehash(#[\SensitiveParameter] $hash)
	{
		if (!$this->enabled || !self::bridgeAvailable()) {
			return $this->fallback->needsRehash($hash);
		}
		if (!is_string($hash) || strncmp($hash, self::ARGON2ID_PREFIX, 10) !== 0) {
			// a bcrypt hash on a site that has turned argon2id on: upgrade it at the next login,
			// which is the only moment the plaintext is available
			return true;
		}
		return cfw_argon2_needs_rehash($hash) === true;
	}
}
