<?php

declare(strict_types=1);

namespace Drupal\drupflare\Exec;

use RuntimeException;

/**
 * A command line, or one of its options, that the router will not serve.
 *
 * Carries the reason as its message; the router records it and answers with a failed launch.
 */
final class Refused extends RuntimeException {}
