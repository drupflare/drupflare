<?php

declare(strict_types=1);

namespace Drupal\drupflare\Shim;

use RuntimeException;

/**
 * What a module catching `\RedisException` receives from the stand-in, aliased to that name.
 */
class RedisException extends RuntimeException {}
