<?php

declare(strict_types=1);

namespace Drupal\drupflare\Lock;

use Drupal\Core\Lock\LockBackendInterface;

/**
 * A lock backend for a runtime that has one thread and no clock.
 *
 * Drupal's database lock cannot work here, and fails by burning CPU rather than by erroring.
 * `DatabaseLockBackend::acquire()` stores `microtime(TRUE) + $timeout` as the expiry and
 * `lockMayBeAvailable()` releases the row when `microtime(TRUE) > $expire`. In this runtime
 * `microtime()` returns 0, so a row is written with `expire = 30` and tested against `now = 0`:
 * `0 > 30` is false forever, and a lock once written is never available again.
 *
 * `RouteBuilder::rebuild()` then does the one thing that turns that into an outage:
 *
 *   if (!$this->lock->acquire('router_rebuild')) {
 *     $this->lock->wait('router_rebuild');
 *
 * and `LockBackendAbstract::wait()` polls with `usleep()` for up to 30 seconds. There are no
 * threads to yield to, so `usleep()` spins and those 30 seconds are billed as CPU. Measured on a
 * deployed worker: a module install ends at `outcome: exceededCpu`, 32,500 ms, "Durable Object
 * exceeded its CPU time limit and was reset" -- against 1,746 ms for the same install in a lane
 * where `microtime()` works. The install was never slow; it spent 30 seconds waiting.
 *
 * A lock is also unnecessary, which is why this grants rather than fixes the clock. One site is one
 * Durable Object is one thread, the input gate serialises every request into it, and PHP inside it
 * is single-threaded. The concurrent writer a lock exists to exclude cannot be constructed. So
 * `acquire()` always succeeds and `wait()` never waits.
 *
 * What is given up, stated plainly: cross-request stampede suppression. Two requests that would
 * each rebuild the router both rebuild it instead of the second reusing the first's work. They run
 * one after another rather than at once, so the outcome is correct and the cost is a repeat --
 * against a failure mode that made the operation impossible.
 */
final class CfwLockBackend implements LockBackendInterface
{
	/**
	 * Locks held by this instance, by name.
	 *
	 * @var array<string, true>
	 */
	private array $locks = [];

	/**
	 * This instance's lock id.
	 */
	private ?string $lockId = null;

	/**
	 * {@inheritdoc}
	 */
	public function acquire($name, $timeout = 30.0)
	{
		// no second thread can hold it, so the only question is whether this one already does,
		// and the answer does not change what is returned
		//
		// NOT declared, and that was tried: granting is the CORRECT answer on a single-threaded
		// object, so a declaration here fires on every boot and is a warning nobody can act on
		$this->locks[$name] = true;
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function lockMayBeAvailable($name)
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * FALSE means "the lock is available now, stop waiting", which is the answer that matters:
	 * returning TRUE would send `RouteBuilder` back around to wait again.
	 */
	public function wait($name, $delay = 30)
	{
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function release($name)
	{
		unset($this->locks[$name]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function releaseAll($lock_id = null)
	{
		$this->locks = [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getLockId()
	{
		if ($this->lockId === null) {
			// still unique per instance, because callers compare it rather than parse it. Not
			// derived from a clock: this class exists because there is not one
			$this->lockId = uniqid('cfw', true);
		}
		return $this->lockId;
	}
}
