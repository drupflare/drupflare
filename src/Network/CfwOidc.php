<?php

declare(strict_types=1);

namespace Drupal\drupflare\Network;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * The Drupal end of Tier B: redeem a claims ticket and sign the visitor in.
 *
 * **THE EXCHANGE ALREADY HAPPENED.** The Worker completed the authorization-code exchange and
 * verified the `id_token` signature against the provider's JWKS before this route was reached, so
 * everything here is synchronous and there is nothing to await. That is the whole shape of Tier B --
 * see `src/ops/oidc.ts` in the worker for why it cannot be done from PHP at all: this build carries
 * no OpenSSL, so it could not verify an RS256 `id_token` even if it were handed one.
 *
 * What crosses the boundary is a single-use TICKET, never the claims. The host deletes the row
 * before answering, so a replayed ticket finds nothing -- which matters because a ticket rides in a
 * redirect and therefore lands in browser history and in every proxy log on the path.
 */
final class CfwOidc extends ControllerBase
{
	/**
	 * The `externalauth` provider name this module registers accounts under.
	 *
	 * A constant rather than the issuer, because it identifies WHICH module owns the authmap row.
	 * The issuer scopes the AUTHNAME instead; see {@see self::authname()}.
	 */
	public const PROVIDER = 'drupflare_oidc';

	/**
	 * The query parameter the host redirects back with.
	 */
	public const TICKET_PARAM = 'cfw_oidc';

	/**
	 * Completes a login started at the host's `/__oidc` route.
	 *
	 * @param Request $request
	 *   The incoming request, carrying the ticket.
	 *
	 * @return RedirectResponse
	 *   A redirect to the front page either way; a failure is reported as a message rather than as
	 *   a page, because a login that did not complete must not look like a broken site.
	 */
	public function complete(Request $request): RedirectResponse
	{
		$ticket = (string) $request->query->get(self::TICKET_PARAM, '');
		if ($ticket === '') {
			return $this->refuse('That login link carried no ticket.');
		}

		if (!Host::has('cfwOidcClaims')) {
			Degradation::record(
				'cfwOidcClaims',
				'This deployment did not install the OIDC capability, so a provider login cannot be completed. It is the host half of the login and needs oidc_issuer and oidc_client_id configured on the Worker.',
			);
			return $this->refuse('Single sign-on is not configured on this site.');
		}

		$reply = Host::call('cfwOidcClaims', ['ticket' => $ticket]);
		if (($reply['ok'] ?? false) !== true) {
			// the host's own sentence: expired, already used, or a mismatch
			return $this->refuse(
				(string) ($reply['error'] ?? 'That login could not be completed.'),
			);
		}

		$sub = (string) ($reply['sub'] ?? '');
		$issuer = (string) ($reply['issuer'] ?? '');
		if ($sub === '' || $issuer === '') {
			return $this->refuse('That login carried no subject.');
		}

		return $this->signIn($sub, $issuer, $reply);
	}

	/**
	 * Hands the verified claims to `externalauth`, which owns the account mapping.
	 *
	 * Declared rather than reimplemented: this module does NOT create users itself. If
	 * `externalauth` is absent the login is refused and the gap is reported on the status page,
	 * because inventing an account-provisioning path here would be a second, untested one.
	 *
	 * @param string $sub
	 *   The provider's subject identifier.
	 * @param string $issuer
	 *   The issuer the subject belongs to.
	 * @param array $claims
	 *   The rest of the verified claims.
	 *
	 * @return RedirectResponse
	 *   Where to send the visitor next.
	 */
	private function signIn(string $sub, string $issuer, array $claims): RedirectResponse
	{
		if (!Drupal::hasService('externalauth.externalauth')) {
			Degradation::record(
				'externalauth',
				'The host verified a provider login, but drupal/externalauth is not installed, so there is nothing to map the identity onto. Enable externalauth to complete single sign-on.',
			);
			return $this->refuse('Single sign-on needs the External Authentication module.');
		}

		try {
			$account = Drupal::service('externalauth.externalauth')->loginRegister(
				self::authname($sub, $issuer),
				self::PROVIDER,
				array_filter([
					'name' => self::accountName($claims),
					'mail' => (string) ($claims['email'] ?? ''),
				]),
			);
		} catch (Throwable $e) {
			Drupal::logger('drupflare')->error(
				'A verified OIDC login could not be mapped: @error',
				[
					'@error' => $e->getMessage(),
				],
			);
			return $this->refuse('That login could not be mapped to an account.');
		}

		if ($account === false) {
			return $this->refuse('That account is blocked.');
		}

		return new RedirectResponse('/');
	}

	/**
	 * The authmap key, scoped by ISSUER.
	 *
	 * A `sub` is unique within one provider and says nothing across providers, so keying on it alone
	 * would let a subject from a newly-configured issuer take over an existing account that happened
	 * to share the identifier.
	 *
	 * @param string $sub
	 *   The subject.
	 * @param string $issuer
	 *   The issuer it came from.
	 *
	 * @return string
	 *   The authmap name.
	 */
	public static function authname(string $sub, string $issuer): string
	{
		return rtrim($issuer, '/') . '|' . $sub;
	}

	/**
	 * A username from the claims, falling back through what a provider might send.
	 *
	 * @param array $claims
	 *   The verified claims.
	 *
	 * @return string
	 *   A username, or an empty string to let externalauth generate one.
	 */
	public static function accountName(array $claims): string
	{
		foreach (['name', 'preferred_username', 'email'] as $key) {
			$value = trim((string) ($claims[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Reports a failed login as a message and sends the visitor home.
	 *
	 * @param string $reason
	 *   What went wrong, in the visitor's terms.
	 *
	 * @return RedirectResponse
	 *   A redirect to the front page.
	 */
	private function refuse(string $reason): RedirectResponse
	{
		Drupal::messenger()->addError($reason);
		return new RedirectResponse('/');
	}
}
