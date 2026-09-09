<?php

namespace Drupal\drupflare\Ops;

use Drupal;
use Drupal\Core\Config\StorageInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Throwable;

/**
 * Executes the operations {@see OpsRegistry} declares unsliced, plus the paged halves of cex/cim.
 *
 * The registry said what each operation costs and nothing ran any of them: `/ops` offered eight
 * commands, `status` was the only one that answered, and two of the remaining seven named no driver
 * at all. This is the driver for everything that genuinely fits one invocation, and the paging
 * contract for the two that do not.
 *
 * WHY PAGING RATHER THAN SLICING for cex and cim. A slice is a unit of a chain the host drives on an
 * alarm; a page is a bounded read the caller asks for again. Config export is a pure read with a
 * stable order, so the caller can hold the cursor and nothing has to be persisted between calls --
 * which is the cheaper of the two and needs no state to go stale.
 */
final class OpsRunner
{
	/**
	 * Config objects per cex page; a standard site holds 164, so this is 7 pages.
	 */
	public const PAGE = 25;

	/**
	 * Runs one operation.
	 *
	 * @param string $name
	 *   A registry operation name.
	 * @param array $args
	 *   Positional arguments, already stripped of flags.
	 * @param array $options
	 *   Offset/limit for the paged operations, and 'payload' for cim.
	 *
	 * @return array
	 *   Always carries 'ok'. On success the operation's own keys; on failure 'error'.
	 */
	public static function run(string $name, array $args = [], array $options = []): array
	{
		try {
			return match ($name) {
				'requirements' => self::requirements(),
				'state-get' => self::stateGet($args),
				'state-set' => self::stateSet($args),
				'config-get' => self::configGet($args),
				'config-set' => self::configSet($args),
				'role-list' => self::roleList(),
				'user-info' => self::userInfo($args),
				'watchdog-show' => self::watchdogShow($args),
				'cache-clear' => self::cacheClear($args),
				'queue-list' => self::queueList(),
				'cex' => self::configExport($options),
				'cim' => self::configImport($options),
				default => ['ok' => false, 'error' => sprintf('%s has no driver here', $name)],
			};
		} catch (Throwable $e) {
			return ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
		}
	}

	/**
	 * The status report, as data.
	 *
	 * @return array
	 *   Requirements keyed by id, each with severity, title and value.
	 */
	private static function requirements(): array
	{
		$out = [];
		foreach (\Drupal::service('system.manager')->listRequirements() as $id => $r) {
			$out[$id] = [
				'title' => (string) ($r['title'] ?? $id),
				'value' => isset($r['value']) ? (string) $r['value'] : null,
				// RequirementSeverity is an enum in 11.x and an int before it; both stringify
				'severity' => isset($r['severity'])
					? (is_object($r['severity'])
						? $r['severity']->name ?? (string) $r['severity']->value
						: (string) $r['severity'])
					: 'Info',
			];
		}
		return ['ok' => true, 'requirements' => $out, 'count' => count($out)];
	}

	/**
	 * Reads one state key.
	 *
	 * @param array $args
	 *   Positional: key.
	 *
	 * @return array
	 *   The value, or an error naming the missing argument.
	 */
	private static function stateGet(array $args): array
	{
		$key = (string) ($args[0] ?? '');
		if ($key === '') {
			return ['ok' => false, 'error' => 'state-get needs a key'];
		}
		return ['ok' => true, 'key' => $key, 'value' => \Drupal::state()->get($key)];
	}

	/**
	 * Writes one state key.
	 *
	 * @param array $args
	 *   Positional: key, value. A value that parses as JSON is stored decoded, so a number stays a
	 *   number.
	 *
	 * @return array
	 *   The stored value.
	 */
	private static function stateSet(array $args): array
	{
		$key = (string) ($args[0] ?? '');
		if ($key === '' || !array_key_exists(1, $args)) {
			return ['ok' => false, 'error' => 'state-set needs a key and a value'];
		}
		$raw = (string) $args[1];
		$decoded = json_decode($raw, true);
		$value = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
		\Drupal::state()->set($key, $value);
		return ['ok' => true, 'key' => $key, 'value' => $value];
	}

	/**
	 * Reads a config object, or one key inside it.
	 *
	 * @param array $args
	 *   Positional: name, and optionally a key inside it.
	 *
	 * @return array
	 *   The data.
	 */
	private static function configGet(array $args): array
	{
		$name = (string) ($args[0] ?? '');
		if ($name === '') {
			return ['ok' => false, 'error' => 'config-get needs a config object name'];
		}
		$config = \Drupal::config($name);
		$key = isset($args[1]) ? (string) $args[1] : null;
		return [
			'ok' => true,
			'name' => $name,
			'key' => $key,
			'value' => $key === null ? $config->get() : $config->get($key),
		];
	}

	/**
	 * Writes one key of an editable config object.
	 *
	 * @param array $args
	 *   Positional: name, key, value.
	 *
	 * @return array
	 *   What was written.
	 */
	private static function configSet(array $args): array
	{
		$name = (string) ($args[0] ?? '');
		$key = (string) ($args[1] ?? '');
		if ($name === '' || $key === '' || !array_key_exists(2, $args)) {
			return ['ok' => false, 'error' => 'config-set needs a name, a key and a value'];
		}
		$raw = (string) $args[2];
		$decoded = json_decode($raw, true);
		$value = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
		\Drupal::configFactory()->getEditable($name)->set($key, $value)->save();
		return ['ok' => true, 'name' => $name, 'key' => $key, 'value' => $value];
	}

	/**
	 * Every role and its permission count.
	 *
	 * @return array
	 *   Roles keyed by id.
	 */
	private static function roleList(): array
	{
		$out = [];
		foreach (Role::loadMultiple() as $id => $role) {
			$out[$id] = [
				'label' => (string) $role->label(),
				'isAdmin' => (bool) $role->isAdmin(),
				'permissions' => count($role->getPermissions()),
			];
		}
		return ['ok' => true, 'roles' => $out, 'count' => count($out)];
	}

	/**
	 * One account by name, or null.
	 *
	 * @param string $name
	 *   The account name.
	 *
	 * @return User|null
	 *   The account, or null when no account carries that name.
	 */
	private static function userByName(string $name): ?User
	{
		$found = Drupal::entityTypeManager()
			->getStorage('user')
			->loadByProperties(['name' => $name]);
		$user = $found ? reset($found) : null;
		return $user instanceof User ? $user : null;
	}

	/**
	 * One account, by name or uid.
	 *
	 * The password hash is never returned; nothing here needs it and a terminal that prints one is a
	 * credential leak with a prompt in front of it.
	 *
	 * @param array $args
	 *   Positional: an account name or a uid.
	 *
	 * @return array
	 *   The account, or an error.
	 */
	private static function userInfo(array $args): array
	{
		$who = (string) ($args[0] ?? '');
		if ($who === '') {
			return ['ok' => false, 'error' => 'user-info needs a name or a uid'];
		}
		// user_load_by_name() is deprecated in 11.4 and removed in 13.0
		$user = ctype_digit($who) ? User::load((int) $who) : self::userByName($who);
		if (!$user) {
			return ['ok' => false, 'error' => sprintf('no account matches %s', $who)];
		}
		return [
			'ok' => true,
			'uid' => (int) $user->id(),
			'name' => (string) $user->getAccountName(),
			'mail' => (string) $user->getEmail(),
			'active' => (bool) $user->isActive(),
			'created' => (int) $user->getCreatedTime(),
			'roles' => array_values($user->getRoles()),
		];
	}

	/**
	 * The most recent log entries.
	 *
	 * Reads the table rather than the dblog service, because a site with dblog uninstalled should
	 * report an empty log rather than a missing service.
	 *
	 * @param array $args
	 *   Positional: count, default 10, capped at 50.
	 *
	 * @return array
	 *   Entries newest first.
	 */
	private static function watchdogShow(array $args): array
	{
		$count = isset($args[0]) ? (int) $args[0] : 10;
		$count = max(1, min($count, 50));
		$db = \Drupal::database();
		if (!$db->schema()->tableExists('watchdog')) {
			return ['ok' => true, 'entries' => [], 'note' => 'there is no watchdog table'];
		}
		$rows = $db
			->select('watchdog', 'w')
			->fields('w', ['wid', 'type', 'severity', 'message', 'variables', 'timestamp'])
			->orderBy('wid', 'DESC')
			->range(0, $count)
			->execute()
			->fetchAll();
		$entries = [];
		foreach ($rows as $row) {
			$vars = @unserialize((string) $row->variables, ['allowed_classes' => false]);
			$replace = [];
			if (is_array($vars)) {
				foreach ($vars as $name => $value) {
					// `allowed_classes => false` turns any object into __PHP_Incomplete_Class, and
					// casting one to string THROWS -- which took the whole op down on the first real
					// log row it met. Drupal's own variables carry TranslatableMarkup and exceptions,
					// so this is the common case rather than an edge one
					if (is_scalar($value) || $value === null) {
						$replace[(string) $name] = (string) $value;
					} elseif (is_array($value)) {
						$replace[(string) $name] = json_encode($value);
					} else {
						$replace[(string) $name] = '[object]';
					}
				}
			}
			$entries[] = [
				'wid' => (int) $row->wid,
				'type' => (string) $row->type,
				'severity' => (int) $row->severity,
				'timestamp' => (int) $row->timestamp,
				'message' =>
					$replace === []
						? (string) $row->message
						: strtr((string) $row->message, $replace),
			];
		}
		return ['ok' => true, 'entries' => $entries, 'count' => count($entries)];
	}

	/**
	 * Clears ONE cache bin.
	 *
	 * Not `drupal_flush_all_caches()`, which the registry measures at 282.9 ms in wasm and marks
	 * sliced. One bin is a delete against one table and fits an invocation.
	 *
	 * @param array $args
	 *   Positional: bin, without the cache. service prefix.
	 *
	 * @return array
	 *   What was cleared.
	 */
	private static function cacheClear(array $args): array
	{
		$bin = (string) ($args[0] ?? '');
		if ($bin === '') {
			return ['ok' => false, 'error' => 'cache-clear needs a bin, for example `render`'];
		}
		$service = 'cache.' . $bin;
		if (!\Drupal::hasService($service)) {
			return ['ok' => false, 'error' => sprintf('there is no %s bin', $bin)];
		}
		\Drupal::service($service)->deleteAll();
		return ['ok' => true, 'bin' => $bin];
	}

	/**
	 * Every queue with a worker, and how deep it is.
	 *
	 * @return array
	 *   Queues keyed by plugin id.
	 */
	private static function queueList(): array
	{
		$out = [];
		$queueFactory = \Drupal::service('queue');
		$definitions = \Drupal::service('plugin.manager.queue_worker')->getDefinitions();
		foreach ($definitions as $id => $definition) {
			$out[$id] = [
				'title' => (string) ($definition['title'] ?? $id),
				'items' => (int) $queueFactory->get($id)->numberOfItems(),
				'cron' => isset($definition['cron']) ? $definition['cron']['time'] ?? 0 : null,
			];
		}
		return ['ok' => true, 'queues' => $out, 'count' => count($out)];
	}

	/**
	 * One page of the active configuration.
	 *
	 * @param array $options
	 *   Offset and limit.
	 *
	 * @return array
	 *   The page, plus 'nextOffset' when there is more, so a caller can drive it to completion.
	 */
	private static function configExport(array $options): array
	{
		$storage = \Drupal::service('config.storage');
		assert($storage instanceof StorageInterface);
		$names = $storage->listAll();
		sort($names);
		$total = count($names);
		$offset = max(0, (int) ($options['offset'] ?? 0));
		$limit = max(1, min((int) ($options['limit'] ?? self::PAGE), self::PAGE));
		$slice = array_slice($names, $offset, $limit);

		$objects = [];
		foreach ($slice as $name) {
			$objects[$name] = $storage->read($name);
		}
		$next = $offset + count($slice);
		return [
			'ok' => true,
			'total' => $total,
			'offset' => $offset,
			'returned' => count($slice),
			'nextOffset' => $next < $total ? $next : null,
			'config' => $objects,
		];
	}

	/**
	 * Writes config objects back.
	 *
	 * NOT `ConfigImporter`. A full import computes a diff over the whole tree, deletes what the
	 * payload omits and rebuilds the container, which is the sliced cost the registry records. This
	 * writes exactly the objects it was handed, so a partial payload cannot delete a site.
	 *
	 * @param array $options
	 *   Carries payload as a name-to-data map.
	 *
	 * @return array
	 *   The names written.
	 */
	private static function configImport(array $options): array
	{
		$payload = $options['payload'] ?? null;
		if (!is_array($payload) || $payload === []) {
			return ['ok' => false, 'error' => 'cim needs a payload of config objects'];
		}
		if (count($payload) > self::PAGE) {
			return [
				'ok' => false,
				'error' => sprintf('cim takes at most %d objects per call', self::PAGE),
			];
		}
		$storage = \Drupal::service('config.storage');
		assert($storage instanceof StorageInterface);
		$written = [];
		foreach ($payload as $name => $data) {
			if (!is_string($name) || !is_array($data)) {
				continue;
			}
			$storage->write($name, $data);
			$written[] = $name;
		}
		return ['ok' => true, 'written' => $written, 'count' => count($written)];
	}
}
