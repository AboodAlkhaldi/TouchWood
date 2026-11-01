<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use RuntimeException;

/**
 * A provider did not take a message (ops.md §7): retried by the queue, then recorded as failed -
 * never lost silently. The message says what failed, never what was sent: no address, no text.
 */
final class ProviderFailed extends RuntimeException {}
