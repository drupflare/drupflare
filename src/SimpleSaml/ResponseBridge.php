<?php

declare(strict_types=1);

namespace Drupal\drupflare\SimpleSaml;

use Drupal\Core\Extension\ModuleHandlerInterface;
use SAML2\Compat\ContainerSingleton;
use SimpleSAML\Compat\SspContainer;
use SimpleSAML\Assert\AssertionFailedException;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Module;
use SimpleSAML\Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes SimpleSAMLphp's CGI habits work in a request that has no SAPI to send headers.
 *
 * Only active when `simplesamlphp_auth` is enabled. SimpleSAMLphp:
 *
 * - redirects with `header('Location')`, an echoed page and `exit`. An `exit` ends the request before
 *   the host can answer it (the serve answers 500, no JSON in the output), so it is turned off with
 *   the library's own `SIMPLESAMLPHP_TEST_NOEXIT` constant, and the header is read back from
 *   `headers_list()` here and turned into the response;
 * - serves its endpoints from a web server alias, which a route cannot stand in for because they have
 *   a variable depth, so they are answered from the request event;
 * - saves its session, and sets the session cookie, from a `header_register_callback` that only a
 *   SAPI sending headers runs, so the session is saved explicitly before the headers are read;
 * - expects `_include.php` to have installed its SAML2 container, which Drupal never includes.
 */
class ResponseBridge implements EventSubscriberInterface
{
	/**
	 * The public prefix SimpleSAMLphp's own documentation gives for its endpoints.
	 */
	public const PREFIX = '/simplesaml/module.php/';

	private const SCRIPT = '/simplesaml/module.php';

	/**
	 * Whether this request's output is being buffered for the response event.
	 *
	 * @var bool
	 */
	private bool $buffering = false;

	/**
	 * Whether SimpleSAMLphp's host-side setup has run in this interpreter.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	public function __construct(private readonly ModuleHandlerInterface $moduleHandler) {}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::REQUEST => ['start', 1000],
			KernelEvents::RESPONSE => ['finish', -1000],
		];
	}

	/**
	 * Starts buffering and answers a SimpleSAMLphp endpoint ahead of the router.
	 *
	 * @param RequestEvent $event
	 *   The request event.
	 */
	public function start(RequestEvent $event): void
	{
		if (!$event->isMainRequest() || !$this->active()) {
			return;
		}
		$this->boot();
		if (!headers_sent()) {
			header_remove('Location');
		}
		ob_start();
		$this->buffering = true;
		$path = $event->getRequest()->getPathInfo();
		if (str_starts_with($path, self::PREFIX)) {
			// answered here, ahead of the router: Drupal matches routes by segment count and these
			// endpoints have a variable depth
			$event->setResponse($this->serve($event->getRequest()));
		}
	}

	/**
	 * Saves the session and turns a raw redirect header into the response.
	 *
	 * @param ResponseEvent $event
	 *   The response event.
	 */
	public function finish(ResponseEvent $event): void
	{
		if (!$this->buffering) {
			return;
		}
		$this->buffering = false;
		$this->saveSession();
		$echoed = (string) ob_get_clean();
		$location = $this->location();
		if ($location === null) {
			return;
		}
		$event->setResponse(
			new Response($echoed, http_response_code() ?: 303, [
				'Location' => $location,
				'Cache-Control' => 'no-store',
			]),
		);
	}

	/**
	 * Runs SimpleSAMLphp's `module.php` for one of its endpoints.
	 *
	 * A web server normally aliases `/simplesaml` to SimpleSAMLphp's public directory, and the
	 * assertion consumer, logout and metadata endpoints live there.
	 */
	protected function serve(Request $request): Response
	{
		if (!class_exists(Module::class)) {
			return new Response('Not Found', 404);
		}
		// A request built from the parts, so nothing Drupal routed on it comes along. SimpleSAMLphp
		// derives its base URL from SCRIPT_FILENAME, which Drupal points at index.php.
		$ssp = new Request(
			$request->query->all(),
			$request->request->all(),
			[],
			$request->cookies->all(),
			$request->files->all(),
			$request->server->all(),
			$request->getContent(),
		);
		$ssp->server->set(
			'SCRIPT_FILENAME',
			'/drupal/vendor/simplesamlphp/simplesamlphp/public/module.php',
		);
		$ssp->server->set('PHP_SELF', self::SCRIPT);
		$ssp->server->set('PATH_INFO', substr($request->getPathInfo(), strlen(self::SCRIPT)));
		$response = Module::process($ssp);
		if (!($response instanceof RunnableResponse)) {
			return $response;
		}
		// the redirect runs when the response is sent, which nothing here does
		ob_start();
		try {
			$response->sendContent();
		} catch (AssertionFailedException) {
			// SimpleSAMLphp asserts that nothing runs after its redirect, because it exits there
		}
		return new Response((string) ob_get_clean(), http_response_code() ?: 200);
	}

	/**
	 * Whether simplesamlphp_auth is enabled.
	 *
	 * @return bool
	 *   TRUE when the module is enabled.
	 */
	protected function active(): bool
	{
		return $this->moduleHandler->moduleExists('simplesamlphp_auth');
	}

	/**
	 * What a raw header() call left for the response.
	 *
	 * @return string|null
	 *   The Location value, or NULL when no redirect was set.
	 */
	protected function location(): ?string
	{
		$found = null;
		foreach (headers_list() as $line) {
			if (stripos($line, 'location:') === 0) {
				$found = trim(substr($line, 9));
			}
		}
		return $found;
	}

	/**
	 * Saves SimpleSAMLphp's session, which only a SAPI that sends headers would do.
	 */
	protected function saveSession(): void
	{
		if (class_exists(Session::class, false)) {
			Session::getSessionFromRequest()->save();
		}
	}

	/**
	 * Runs the host-side setup SimpleSAMLphp expects once per interpreter.
	 */
	private function boot(): void
	{
		if ($this->booted) {
			return;
		}
		$this->booted = true;
		if (!defined('SIMPLESAMLPHP_TEST_NOEXIT')) {
			define('SIMPLESAMLPHP_TEST_NOEXIT', true);
		}
		// SimpleSAMLphp's Symfony kernel scans this directory, and the installed tree lacks it
		@mkdir('/drupal/vendor/symfony/validator/Resources/translations', 0777, true);
		if (class_exists(SspContainer::class)) {
			ContainerSingleton::setContainer(new SspContainer());
		}
	}
}
