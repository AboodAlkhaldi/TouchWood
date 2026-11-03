<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use RuntimeException;

/**
 * A provider did not take a message (ops.md §7): retried by the queue, then recorded as failed -
 * never lost silently. The message says what failed, never what was sent: no address, no text.
 *
 * `permanent`: trying again cannot help until a person mends something - a key refused, no credit, an
 * address refused. A job goes to the failed jobs at once (ProviderFailureRules) instead of asking again.
 * `retryAfter`: the provider said how many seconds to wait, in place of the job's own wait.
 */
final class ProviderFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $permanent = false,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
