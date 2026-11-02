<?php

/**
 * @file
 * Declaration-only stubs for the SimpleSAMLphp classes `src/SimpleSaml/` names, for STATIC ANALYSIS ONLY.
 *
 * THIS FILE MUST NEVER BE LOADED AT RUNTIME. It is kept out the same way `vrzno.php` is: it is not
 * in `composer.json` autoload, `stubs/` is `export-ignore` in `.gitattributes`, and
 * `worker/scripts/gen-driver-assets.ts` skips any `stubs/` path when packing the module.
 *
 * WHY IT EXISTS. SimpleSAMLphp reaches a site through `drupal/simplesamlphp_auth`, never through
 * this module's own dependencies, so PHPStan cannot find these classes in CI. Installing the whole
 * application as a dev dependency to read five signatures costs far more than this file.
 */

namespace SimpleSAML\Store {
	interface StoreInterface
	{
		public function get(string $type, string $key): mixed;

		public function set(string $type, string $key, mixed $value, ?int $expire = null): void;

		public function delete(string $type, string $key): void;
	}
}

namespace SimpleSAML\HTTP {
	use Symfony\Component\HttpFoundation\Response;

	class RunnableResponse extends Response
	{
		public function sendContent(): static
		{
			return $this;
		}
	}
}

namespace SimpleSAML\Assert {
	use InvalidArgumentException;

	class AssertionFailedException extends InvalidArgumentException {}
}

namespace SAML2\Compat {
	class ContainerSingleton
	{
		public static function setContainer(object $container): void {}
	}
}
