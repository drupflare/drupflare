<?php

declare(strict_types=1);

namespace Drupal\drupflare\Routing;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Access\CsrfTokenGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Substitutes CSRF placeholders that nothing else replaced.
 *
 * RouteProcessorCsrf does not put a token in a link on an HTML request. It emits
 * Crypt::hashBase64($path) as a placeholder and attaches a #lazy_builder to replace it later, so a
 * cached page does not carry one visitor's token. Something has to perform that replacement, and on
 * one path in core nothing does: UpdateHooks::pageTop() passes the requirement description through
 * Renderer::renderInIsolation(), which renders the markup and DISCARDS the bubbled
 * #attached[placeholders] recipe. The placeholder then travels into a status message as a plain
 * string, and no later render has anything left to substitute it with.
 *
 * The result reaches the browser as token=<sha256 of the route path>, which is keyless and therefore
 * the SAME 43 characters on every site in the world. CsrfTokenGenerator::validate() refuses it, so
 * every link built that way answers 403 with "'csrf_token' URL query argument is invalid".
 *
 * This is survivable on an ordinary host because the message only appears while the update module
 * has no data. Here it never stops: a Worker cannot fetch synchronously, so update data is always
 * deferred and this message is on every admin page of every site, permanently.
 *
 * Reproduced 2026-09-14: two sessions on two freshly provisioned sites, each with its own private
 * key, hash salt and session seed, were both handed
 * QJoWTyS5Bcf8KP_gvhmjUwm-s1rlrvSqL3XHgVsxEKQ, which is exactly
 * Crypt::hashBase64('admin/reports/status/run-cron').
 */
final class CsrfPlaceholderSubscriber implements EventSubscriberInterface
{
	/**
	 * Constructs the subscriber.
	 *
	 * @param CsrfTokenGenerator $csrfToken
	 *   The generator whose placeholder this repairs.
	 */
	public function __construct(private readonly CsrfTokenGenerator $csrfToken) {}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		// AFTER the response is built and before it is sent. A lower priority than
		// BigPipe's own subscriber is deliberate: a placeholder BigPipe can still replace should be
		// replaced by BigPipe, and only what survives that is repaired here.
		return [KernelEvents::RESPONSE => [['onResponse', -512]]];
	}

	/**
	 * Replaces any surviving CSRF placeholder with a token for the session being served.
	 *
	 * @param ResponseEvent $event
	 *   The response event.
	 */
	public function onResponse(ResponseEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}
		$response = $event->getResponse();
		$type = (string) $response->headers->get('Content-Type', '');
		if (!str_contains($type, 'text/html')) {
			return;
		}
		$html = $response->getContent();
		// the cheap gate: no query token anywhere means there is nothing to look at, and this runs
		// on every HTML response
		if (!is_string($html) || !str_contains($html, 'token=')) {
			return;
		}

		$fixed = preg_replace_callback(
			'#(?<=["\'])([^"\']*?)([?&]|&amp;)token=([A-Za-z0-9_-]{43})#',
			fn(array $m): string => $m[1] . $m[2] . 'token=' . $this->substitute($m[1], $m[3]),
			$html,
		);
		if (is_string($fixed) && $fixed !== $html) {
			$response->setContent($fixed);
		}
	}

	/**
	 * One candidate, replaced only when it provably IS this path's placeholder.
	 *
	 * The comparison is what makes this safe to run over every response. A real token is not equal
	 * to the hash of the path it appears on, so a correctly substituted link is left alone and only
	 * a placeholder is rewritten. Nothing here trusts the markup.
	 *
	 * @param string $href
	 *   The href up to the query argument, which may carry earlier query arguments.
	 * @param string $value
	 *   The 43-character value found after token=.
	 *
	 * @return string
	 *   The real token, or the value unchanged.
	 */
	private function substitute(string $href, string $value): string
	{
		$path = $href;
		$query = strpos($path, '?');
		if ($query !== false) {
			$path = substr($path, 0, $query);
		}
		// generateRoutePath() has no leading slash, and this site is always served from the root
		$path = ltrim(html_entity_decode($path), '/');
		if ($path === '' || !hash_equals(Crypt::hashBase64($path), $value)) {
			return $value;
		}
		return $this->csrfToken->get($path);
	}
}
