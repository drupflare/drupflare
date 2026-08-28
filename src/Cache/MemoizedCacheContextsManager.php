<?php

declare(strict_types=1);

namespace Drupal\drupflare\Cache;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\Context\ContextCacheKeys;

/**
 * Answers a repeated cache-context token list from a per-request memo.
 *
 * Core recomputes every time. `convertTokensToKeys()` runs `optimizeTokens()`, then calls
 * `getService($id)->getContext($parameter)` once per surviving token and merges cacheable metadata
 * for the ones optimised away - and nothing remembers that it just did exactly that.
 *
 * Measured on a steady-state front-page render, native, n=4 with zero spread
 * (scripts/bench/bench-context-memo.php): 51 convert calls over 13 distinct token lists, so
 * 38 of them - 74.5% - repeat a list already answered in the same request. Zero token lists
 * produced two different answers, which is what makes the memo sound rather than merely cheap.
 * `optimizeTokens()` is called 62 times over the same 13 lists and 51 of those are nested inside
 * convert, so the memo removes that work too.
 *
 * THE GENERATION IS NOT JUST THE REQUEST. A context value can change without the request
 * changing: AccountSwitcher swaps the current user mid-request, and `user.permissions`,
 * `user.roles` and `user` all read from it. Keying on the request alone would serve a key computed
 * for the previous account, which is the uid-1 leak shape this project has already shipped once.
 * The generation therefore carries the account id as well, and
 * CacheContextsMemoTest asserts a switch invalidates.
 *
 * Sorted keys, because the answer depends on the SET of tokens rather than their order - core sorts
 * the optimised list itself before building keys. Sorting here turns two orderings of one set into
 * one memo entry instead of two.
 */
final class MemoizedCacheContextsManager extends CacheContextsManager
{
	/**
	 * Sorted token list => the keys object it produced.
	 *
	 * @var array<string, ContextCacheKeys>
	 */
	private array $memo = [];

	/**
	 * What the memo was built for; anything else empties it.
	 */
	private ?string $generation = null;

	/**
	 * {@inheritdoc}
	 */
	public function convertTokensToKeys(array $context_tokens)
	{
		$generation = $this->generation();
		if ($generation !== $this->generation) {
			$this->memo = [];
			$this->generation = $generation;
		}

		$sorted = $context_tokens;
		sort($sorted);
		$key = implode(',', $sorted);

		return $this->memo[$key] ??= parent::convertTokensToKeys($context_tokens);
	}

	/**
	 * Identifies the state every cache context is a function of.
	 *
	 * Read from the container rather than injected, because this class is constructed with core's
	 * own argument list and adding one would fork the definition.
	 *
	 * @return string
	 *   The generation; a change empties the memo.
	 */
	private function generation(): string
	{
		$request = null;
		if ($this->container->has('request_stack')) {
			$request = $this->container->get('request_stack')->getCurrentRequest();
		}
		$account = $this->container->has('current_user')
			? $this->container->get('current_user')->id()
			: null;

		return ($request !== null ? spl_object_id($request) : 0) . ':' . var_export($account, true);
	}
}
