<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use RuntimeException;

/**
 * A provider did not take a message (ops.md §7): retried by the queue, then recorded as failed -
 * never lost silently. The message says what failed, never what was sent: no address, no text.
 *
 * `kind` says what a job does about it (FailureKind). `retryAfter` is the wait, in seconds, of a RateLimited one.
 */
final class ProviderFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly FailureKind $kind = FailureKind::MayPass,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
