<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use Modules\Ops\Domain\Exception\OpsError;
use Shared\Domain\Error\ErrorCategory;

/**
 * A provider did not take a message (ops.md §7): retried by the queue, then recorded as failed -
 * never lost silently. Where nothing is queued - the newsletter box, a security message - it reaches the
 * person as its own error, 503: trying again later may work. The message says what failed, never what was
 * sent: no address, no text; the person reads `ops::errors.provider_failed`, never this message.
 */
final class ProviderFailed extends OpsError
{
    public function type(): string
    {
        return 'ops.provider_failed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
