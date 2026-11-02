<?php

namespace SimpleSAML\Store {
	interface StoreInterface
	{
		public function get(string $type, string $key): mixed;

		public function set(string $type, string $key, mixed $value, ?int $expire = null): void;

		public function delete(string $type, string $key): void;
	}
}

namespace SimpleSAML {
	class SessionHandler
	{
		protected static ?object $sessionHandler = null;

		public static function hold(object $handler): void
		{
			self::$sessionHandler = $handler;
		}

		public static function holds(): bool
		{
			return self::$sessionHandler !== null;
		}
	}

	class Session
	{
		public static ?Session $instance = null;

		public static function clearInternalState(): void
		{
			self::$instance = null;
		}
	}
}

namespace Drupal\simplesamlphp_auth\Service {
	class SimplesamlphpAuthManager
	{
		protected mixed $instance = null;
		protected mixed $attributes = null;

		public function remember(mixed $instance, mixed $attributes): void
		{
			$this->instance = $instance;
			$this->attributes = $attributes;
		}

		public function holds(): bool
		{
			return $this->instance !== null || $this->attributes !== null;
		}
	}
}
