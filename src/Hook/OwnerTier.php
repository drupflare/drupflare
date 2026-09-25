<?php

declare(strict_types=1);

namespace Drupal\drupflare\Hook;

use Drupal;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\user\Entity\Role;
use Drupal\user\Form\RoleSettingsForm;
use Drupal\user\Form\UserPermissionsForm;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Keeps `administer drupflare owner` from being granted by anyone who does not hold it.
 *
 * Drupal lets whoever holds `administer permissions` tick any box, and `restrict access` is only a
 * warning. This tier carries code delivery and secrets, so a permission that could grant itself would
 * not be a tier. The forms disable what the actor cannot grant, and the presave hooks put back any
 * change that reaches storage another way.
 *
 * An administrator role carries every permission, so it carries this one; assigning it or marking a
 * role as the administrator role is the same grant.
 */
final class OwnerTier
{
	public const PERMISSION = 'administer drupflare owner';

	/**
	 * The role claim assigns to uid 1, so a team can share the tier without sharing uid 1.
	 */
	public const ROLE = 'drupflare_owner';

	public const ROLE_PERMISSIONS = [
		self::PERMISSION,
		'administer drupflare site',
		'view drupflare status',
	];

	/**
	 * Whether the acting user may grant or withdraw the owner tier.
	 *
	 * A run the host drives, such as the claim or reconciliation, handles no routed request and acts
	 * for the owner token's holder, which outranks the role.
	 */
	public static function actorMayGrant(): bool
	{
		$user = Drupal::currentUser();
		// everything from storage, not hasPermission(): inside a presave the entity being saved already
		// holds the new grant in memory, and the acting account may be that same entity
		$roles = $user->getRoles();
		if ($user->isAuthenticated()) {
			$account = Drupal::entityTypeManager()->getStorage('user')->loadUnchanged($user->id());
			$roles = $account instanceof UserInterface ? $account->getRoles() : [];
		}
		$storage = Drupal::entityTypeManager()->getStorage('user_role');
		foreach ($roles as $rid) {
			$stored = $storage->loadUnchanged($rid);
			if ($stored instanceof RoleInterface && self::carries($stored)) {
				return true;
			}
		}
		// uid 1 holds every permission while the super user is enabled, whatever its roles say
		if ((int) $user->id() === 1 && $user->hasPermission(self::PERMISSION)) {
			return true;
		}
		$request = Drupal::requestStack()->getCurrentRequest();
		return $user->isAnonymous() && ($request === null || !$request->attributes->has('_route'));
	}

	/**
	 * Whether a role, as it stands, carries the owner tier.
	 */
	public static function carries(?RoleInterface $role): bool
	{
		return $role !== null && ($role->isAdmin() || $role->hasPermission(self::PERMISSION));
	}

	/**
	 * The ids of the roles that carry the owner tier.
	 *
	 * @return string[]
	 *   Role ids.
	 */
	public static function carryingRoles(): array
	{
		return array_keys(array_filter(Role::loadMultiple(), [self::class, 'carries']));
	}

	/**
	 * Creates the owner role if absent and gives it to an account.
	 *
	 * @return string[]
	 *   What changed, for the caller's report.
	 */
	public static function establish(UserInterface $account): array
	{
		$changed = [];
		$role = Role::load(self::ROLE);
		if ($role === null) {
			$role = Role::create(['id' => self::ROLE, 'label' => 'Site Owner']);
			$changed[] = 'role';
		}
		foreach (self::ROLE_PERMISSIONS as $permission) {
			if (!$role->hasPermission($permission)) {
				$role->grantPermission($permission);
				$changed[] = 'permission:' . $permission;
			}
		}
		if ($changed !== []) {
			$role->save();
		}
		if (!$account->hasRole(self::ROLE)) {
			$account->addRole(self::ROLE);
			$account->save();
			$changed[] = 'assigned:' . $account->id();
		}
		return $changed;
	}

	/**
	 * Disables every control that would grant or withdraw the tier, for an actor who lacks it.
	 */
	#[Hook('form_alter')]
	public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void
	{
		$object = $form_state->getFormObject();
		$relevant =
			$object instanceof UserPermissionsForm ||
			$object instanceof RoleSettingsForm ||
			in_array($form_id, ['user_form', 'user_register_form'], true);
		if (!$relevant || self::actorMayGrant()) {
			return;
		}
		// a disabled element submits its default value, so the form cannot carry a change
		if (
			$object instanceof UserPermissionsForm &&
			isset($form['permissions'][self::PERMISSION])
		) {
			foreach ($form['permissions'][self::PERMISSION] as &$element) {
				if (is_array($element) && ($element['#type'] ?? null) === 'checkbox') {
					$element['#disabled'] = true;
				}
			}
			unset($element);
		}
		if ($object instanceof RoleSettingsForm && isset($form['admin_role']['user_admin_role'])) {
			$form['admin_role']['user_admin_role']['#disabled'] = true;
		}
		if (
			in_array($form_id, ['user_form', 'user_register_form'], true) &&
			isset($form['account']['roles'])
		) {
			foreach (self::carryingRoles() as $rid) {
				$form['account']['roles'][$rid]['#disabled'] = true;
			}
		}
	}

	/**
	 * Puts back a role's owner tier when an actor without it changed it.
	 */
	#[Hook('user_role_presave')]
	public function rolePresave(RoleInterface $role): void
	{
		if (self::actorMayGrant()) {
			return;
		}
		$before = $role->isNew() ? null : $role->getOriginal();
		if (self::carries($role) === self::carries($before)) {
			return;
		}
		$role->setIsAdmin($before?->isAdmin() ?? false);
		if ($before?->hasPermission(self::PERMISSION)) {
			$role->grantPermission(self::PERMISSION);
		} else {
			$role->revokePermission(self::PERMISSION);
		}
		Drupal::logger('drupflare')->warning(
			'An owner-tier change to role @role was reverted, because the acting user does not hold it.',
			['@role' => $role->id()],
		);
	}

	/**
	 * Puts back an account's owner-tier roles when an actor without the tier changed them.
	 */
	#[Hook('user_presave')]
	public function userPresave(UserInterface $account): void
	{
		if (self::actorMayGrant()) {
			return;
		}
		$carrying = self::carryingRoles();
		$before = $account->isNew()
			? []
			: array_intersect($account->getOriginal()?->getRoles() ?? [], $carrying);
		$after = array_intersect($account->getRoles(), $carrying);
		if (array_values($before) == array_values($after)) {
			return;
		}
		foreach (array_diff($after, $before) as $rid) {
			$account->removeRole($rid);
		}
		foreach (array_diff($before, $after) as $rid) {
			$account->addRole($rid);
		}
		Drupal::logger('drupflare')->warning(
			'An owner-tier role change to user @uid was reverted, because the acting user does not hold it.',
			['@uid' => $account->id()],
		);
	}
}
