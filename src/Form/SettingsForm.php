<?php

declare(strict_types=1);

namespace Drupal\drupflare\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupflare\Host;
use Exception;

/**
 * The runtime levers, edited from Drupal rather than from a redeploy.
 *
 * Every lever here is stored in account KV and read by the front worker on the next configuration
 * read, so changing one needs no deploy. That is the whole reason the writer exists: the values
 * decide how this site trades speed against quota, and they were previously editable only by
 * editing `wrangler.jsonc` and shipping again.
 *
 * EACH FIELD SHOWS ITS SOURCE, which is the half an operator cannot work without. A number with no
 * provenance cannot be acted on -- "60000" means something different when it is an override
 * somebody chose than when it is a default nobody has ever looked at.
 *
 * `PLAN` is deliberately absent. Every lever on this form has a worst case of a slower site; `PLAN`
 * selects a limits profile whose quotas are ACCOUNT-WIDE, while whoever reaches this form is one
 * tenant's administrator. It is an owner-token route on the front worker for that reason, and the
 * host refuses it at every spelling even if a field for it appeared here.
 */
final class SettingsForm extends FormBase
{
	/**
	 * What each lever does, in one line, for the operator reading the form.
	 *
	 * Keyed by lever name; a name with no entry still renders, because the host owns the list and
	 * this map must never be the thing that decides which levers are reachable.
	 */
	private const DESCRIPTIONS = [
		'RENDER_BUDGET_MS' => 'How long a render may take before the request is queued instead.',
		'FILL_BATCH_SIZE' =>
			'Pages regenerated per background pass. Higher spends the row budget faster.',
		'HTTP_DRAIN_LIMIT' => 'Outbound HTTP requests performed per background pass.',
		'MIRROR_LIMIT' => 'Files copied to object storage per background pass.',
		'LAZY_FS_BUDGET_BYTES' =>
			'How much of the Drupal tree is mounted on demand rather than up front.',
		'PREFILL' =>
			'Render the common pages after provisioning, so the first visitor does not wait.',
		'GEN_BUCKET_MS' => 'How long the edge may serve a page from before a content change.',
		'MAIL_TRANSPORT' => 'Which mail transport sends outbound mail.',
		'MAIL_DRAIN_LIMIT' => 'Messages sent per background pass.',
		'SHELL_ASSEMBLY' =>
			'Assemble a cached shell around personalised regions. Off falls back to a full render.',
		'OPCACHE_MODE' =>
			'The opcode cache arm. Every value boots; they differ in speed and memory.',
		'ARGON2' => 'The password hashing algorithm. Affects login cost only.',
		'SITE_LOCATION_HINT' =>
			'Where this site should prefer to run, when the platform can honour it.',
		'REPLICA_COUNT' =>
			'Read replica lanes. Each lane also repeats every write, so a write-heavy site should keep this low.',
		'REPLICA_LAG_MS' => 'How far behind the primary a lane may fall before it stops serving.',
		'SITE_WARM' =>
			'Keep the site resident between requests. Costs quota on an idle site; saves a cold start on a busy one.',
		'EDGE_PLAN' => 'Answer eligible authenticated pages at the edge with no object hop.',
		'ASSET_AGGREGATES' => 'Serve combined CSS and JS built at pack time.',
	];

	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'drupflare_settings';
	}

	/**
	 * Asks the host for the levers in force.
	 *
	 * @return array
	 *   The decoded reply, or a shape carrying `error` when the host refused.
	 */
	private static function read(): array
	{
		try {
			$reply = Host::call('cfwSettings', ['action' => 'get']);
		} catch (Exception $e) {
			return ['ok' => false, 'error' => $e->getMessage()];
		}
		return is_array($reply) ? $reply : ['ok' => false, 'error' => 'the host returned no reply'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$state = self::read();
		if (empty($state['ok'])) {
			$this->messenger()->addError(
				$this->t('The runtime levers are unreadable: @why', [
					'@why' => (string) ($state['error'] ?? 'unknown'),
				]),
			);
			return $form;
		}

		$writable = !empty($state['writable']);
		if (!$writable) {
			$this->messenger()->addWarning(
				$this->t(
					'This deployment has no writable configuration store, so the levers are read only.',
				),
			);
		}

		$form['intro'] = [
			'#markup' =>
				'<p>' .
				$this->t(
					'These take effect on the next configuration read; no deploy is needed. An empty field defers to the value this worker was deployed with.',
				) .
				'</p>',
		];

		$form['levers'] = [
			'#type' => 'table',
			'#header' => [
				$this->t('Lever'),
				$this->t('Value'),
				$this->t('Source'),
				$this->t('What it Does'),
			],
		];

		foreach ($state['levers'] as $lever) {
			$name = (string) $lever['name'];
			$source = (string) ($lever['source'] ?? 'default');
			$form['levers'][$name]['name'] = ['#markup' => '<code>' . $name . '</code>'];
			$form['levers'][$name]['value'] = [
				'#type' => 'textfield',
				'#title' => $name,
				'#title_display' => 'invisible',
				'#default_value' => (string) ($lever['value'] ?? ''),
				'#size' => 20,
				'#disabled' => !$writable,
			];
			$form['levers'][$name]['source'] = ['#markup' => $this->sourceLabel($source)];
			$form['levers'][$name]['what'] = [
				'#markup' => (string) ($this::DESCRIPTIONS[$name] ?? ''),
			];
		}

		if ($writable) {
			$form['actions'] = ['#type' => 'actions'];
			$form['actions']['submit'] = [
				'#type' => 'submit',
				'#value' => $this->t('Save Levers'),
			];
		}
		return $form;
	}

	/**
	 * Renders a source as something an operator can act on.
	 *
	 * @param string $source
	 *   One of `kv`, `var` or `default`.
	 *
	 * @return string
	 *   A short label.
	 */
	private function sourceLabel(string $source): string
	{
		return match ($source) {
			'kv' => (string) $this->t('Override'),
			'var' => (string) $this->t('Deployed'),
			default => (string) $this->t('Default'),
		};
	}

	/**
	 * {@inheritdoc}
	 *
	 * Sends only the levers the operator CHANGED. A patch carrying every field would rewrite the
	 * whole document on every save, which would turn a deployed value into a stored override by
	 * the act of opening the form and pressing save.
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$state = self::read();
		if (empty($state['ok'])) {
			$this->messenger()->addError(
				$this->t('The runtime levers became unreadable; nothing was saved.'),
			);
			return;
		}

		$submitted = (array) $form_state->getValue('levers');
		$patch = [];
		foreach ($state['levers'] as $lever) {
			$name = (string) $lever['name'];
			if (!isset($submitted[$name]['value'])) {
				continue;
			}
			$was = (string) ($lever['value'] ?? '');
			$now = trim((string) $submitted[$name]['value']);
			if ($now !== $was) {
				$patch[$name] = $now;
			}
		}

		if ($patch === []) {
			$this->messenger()->addStatus($this->t('Nothing changed.'));
			return;
		}

		try {
			$reply = Host::call('cfwSettings', ['action' => 'set', 'patch' => $patch]);
		} catch (Exception $e) {
			$this->messenger()->addError(
				$this->t('The save was refused: @why', ['@why' => $e->getMessage()]),
			);
			return;
		}

		if (!is_array($reply) || empty($reply['ok'])) {
			$this->messenger()->addError(
				$this->t('The save was refused: @why', [
					'@why' => (string) (is_array($reply)
						? $reply['error'] ?? 'unknown'
						: 'no reply'),
				]),
			);
			return;
		}

		$accepted = (array) ($reply['accepted'] ?? []);
		if ($accepted !== []) {
			$this->messenger()->addStatus(
				$this->t('Saved: @names', ['@names' => implode(', ', $accepted)]),
			);
		}
		$refused = (array) ($reply['refused'] ?? []);
		if ($refused !== []) {
			$this->messenger()->addWarning(
				$this->t('Refused, because they are not operator levers: @names', [
					'@names' => implode(', ', $refused),
				]),
			);
		}
	}
}
