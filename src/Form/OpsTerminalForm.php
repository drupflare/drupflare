<?php

declare(strict_types=1);

namespace Drupal\drupflare\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\drupflare\Host;
use Drupal\drupflare\Hook\OwnerTier;
use Drupal\drupflare\Ops\CommandLine;
use Drupal\drupflare\Ops\OpsRegistry;
use Exception;

/**
 * An admin page where a Drush user can type the commands this runtime has.
 *
 * There is no shell here: no `exec`, no `proc_open`, no sockets. Drush exists to give a shell a way
 * into Drupal, so it is not shipped. {@see OpsRegistry} covers the eight operations that carry most
 * of its value, and this form is the surface over them.
 *
 * NOTHING RUNS ON SUBMIT WITHOUT A CONFIRMATION STEP for an operation that writes. The form parses
 * the line, shows what it resolved to and what it will cost, and only then offers to run it. A
 * cache rebuild typed by accident is a denial-of-service primitive on this platform -- the next
 * request rebuilds `cache_discovery`, which is 82 entries and 574 bound parameters against a
 * measured ceiling of 100.
 */
final class OpsTerminalForm extends FormBase
{
	/**
	 * What to call this line in a message.
	 *
	 * A package line has no `op`; reading one would be an undefined-index notice on the success
	 * path, which is the half nobody exercises by hand.
	 *
	 * @param array $parsed
	 *   A successful parse result.
	 *
	 * @return string
	 *   The operation name, or the manager and verb.
	 */
	private static function name(array $parsed): string
	{
		return $parsed['kind'] === 'package'
			? sprintf('%s %s', $parsed['typed'], $parsed['verb'])
			: (string) $parsed['op'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'drupflare_ops_terminal';
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$parsed = $form_state->get('parsed');

		$form['line'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Command'),
			'#description' => $this->t(
				'The operations this runtime has, in Drush spelling. A leading <code>drush</code> is accepted and ignored.',
			),
			'#default_value' => (string) $form_state->getValue('line', ''),
			'#attributes' => ['placeholder' => 'en pathauto', 'autocomplete' => 'off'],
			'#required' => true,
		];

		$rows = [];
		foreach (OpsRegistry::operations() as $name => $spec) {
			$rows[] = [
				$name,
				$spec['label'],
				$spec['writes'] ? $this->t('writes') : $this->t('read only'),
				$spec['sliced'] ? $this->t('driven in the background') : $this->t('inline'),
			];
		}
		$form['available'] = [
			'#type' => 'details',
			'#title' => $this->t('Available Operations'),
			'#open' => $parsed === null,
			'table' => [
				'#type' => 'table',
				'#header' => [
					$this->t('Command'),
					$this->t('What it Does'),
					$this->t('Effect'),
					$this->t('How it Runs'),
				],
				'#rows' => $rows,
			],
		];

		$form['actions']['#type'] = 'actions';
		$form['actions']['check'] = [
			'#type' => 'submit',
			'#value' => $this->t('Check'),
			'#submit' => ['::checkCommand'],
		];

		if ($parsed !== null && ($parsed['ok'] ?? false) === true) {
			$form['plan'] = [
				'#type' => 'item',
				'#title' => $this->t('This Will Run'),
				'#markup' => CommandLine::plan($parsed),
				'#weight' => -10,
			];
			$form['actions']['run'] = [
				'#type' => 'submit',
				'#value' => $this->t('Run @what', ['@what' => self::name($parsed)]),
				'#button_type' => 'primary',
			];
		}

		return $form;
	}

	/**
	 * Parses without running, which is the whole first half of the form.
	 *
	 * @param array $form
	 *   The form.
	 * @param \Drupal\Core\Form\FormStateInterface $form_state
	 *   The form state.
	 */
	public function checkCommand(array &$form, FormStateInterface $form_state): void
	{
		$parsed = CommandLine::parse((string) $form_state->getValue('line', ''));
		$form_state->set('parsed', $parsed);
		$form_state->setRebuild(true);

		if (($parsed['ok'] ?? false) !== true) {
			// a REFUSAL and an unknown command read differently: one says "this platform does not
			// do that and here is why", the other says "check your spelling"
			$this->messenger()->addWarning((string) $parsed['error']);
			return;
		}
		if (
			self::deliversCode($parsed) &&
			!$this->currentUser()->hasPermission(OwnerTier::PERMISSION)
		) {
			$form_state->set('parsed', [
				'ok' => false,
				'error' => 'code delivery is not permitted',
			]);
			$this->messenger()->addWarning(
				$this->t(
					'@what delivers code this site did not ship with, which needs the "Own the Drupflare site" permission.',
					[
						'@what' => self::name($parsed),
					],
				),
			);
			return;
		}
		$this->messenger()->addStatus(
			$this->t('@op resolved. Nothing has run yet.', ['@op' => $parsed['op']]),
		);
	}

	/**
	 * Whether a parsed line delivers code the site did not ship with.
	 *
	 * A package line installs from a registry; `en` installs a module, which on this platform means
	 * mounting a tree the pack does not carry. Both are a different trust level from rebuilding a
	 * cache, which is why they have their own permission -- a site can grant the operations terminal
	 * without granting the ability to add code to the runtime.
	 *
	 * @param array $parsed
	 *   A successful parse result.
	 *
	 * @return bool
	 *   TRUE when the line delivers code.
	 */
	private static function deliversCode(array $parsed): bool
	{
		return $parsed['kind'] === 'package' || ($parsed['op'] ?? '') === 'en';
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$parsed = $form_state->get('parsed');
		if (!is_array($parsed) || ($parsed['ok'] ?? false) !== true) {
			// the run button only exists after a successful check, so this is a resubmitted form
			// rather than a user error
			$this->messenger()->addWarning($this->t('Check the command again before running it.'));
			$form_state->setRebuild(true);
			return;
		}

		if (
			self::deliversCode($parsed) &&
			!$this->currentUser()->hasPermission(OwnerTier::PERMISSION)
		) {
			// re-checked at the RUN rather than trusted from the check: the parse rides in form
			// state across a rebuild, so a permission revoked in between would otherwise be missed
			$this->messenger()->addError(
				$this->t('Code delivery is not permitted for this account.'),
			);
			$form_state->setRebuild(true);
			return;
		}

		try {
			$reply = Host::call(
				'cfwOps',
				$parsed['kind'] === 'package'
					? [
						'op' => 'package',
						'manager' => $parsed['manager'],
						'verb' => $parsed['verb'],
						'packages' => $parsed['packages'],
					]
					: ['op' => $parsed['op'], 'args' => $parsed['args']],
			);
		} catch (Exception $e) {
			// DECLARED rather than swallowed: the host is what actually drives these, and a site
			// whose host has no `cfwOps` should say so instead of reporting a silent success
			$this->messenger()->addError(
				$this->t('@what could not be handed to the host: @why', [
					'@what' => self::name($parsed),
					'@why' => $e->getMessage(),
				]),
			);
			$form_state->setRebuild(true);
			return;
		}

		// THE CATCH ABOVE CANNOT FIRE, and the reply was never read, so every command reported
		// "ran." whatever happened. `Host::call()` does not throw -- an absent capability returns
		// `['ok' => false, 'error' => 'capability cfwOps is not installed in this deployment']` --
		// so the one thing the docblock above promises to prevent was exactly what shipped.
		// `Host::call()` is declared `: array`, so the `is_array()` half of this was dead and PHPStan
		// said so. What is NOT dead is the `ok` half, which is the whole reason this block exists
		if (($reply['ok'] ?? false) !== true) {
			$this->messenger()->addError(
				$this->t('@what did not run: @why', [
					'@what' => self::name($parsed),
					'@why' => (string) ($reply['error'] ?? 'the host gave no reason'),
				]),
			);
			$form_state->setRebuild(true);
			return;
		}

		$this->messenger()->addStatus(
			$parsed['sliced'] === true
				? $this->t('@what is queued and runs across background invocations.', [
					'@what' => self::name($parsed),
				])
				: $this->t('@what ran.', ['@what' => self::name($parsed)]),
		);
		if (isset($reply['note'])) {
			$this->messenger()->addStatus((string) $reply['note']);
		}
		$form_state->set('parsed', null);
		$form_state->setRedirectUrl(Url::fromRoute('drupflare.ops_terminal'));
	}
}
