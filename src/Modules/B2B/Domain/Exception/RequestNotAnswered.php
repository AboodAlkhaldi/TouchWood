<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Sending a draft while a request of the last rejection has no answer (b2b.md §1.2, §7,
 * amendment 4): every request must be answered first.
 *
 * It names the request by id, not by its label, in getMessage() only: the draft already shows
 * which one is empty, as MissingRequiredDocument does for a file.
 */
final class RequestNotAnswered extends B2BError
{
    public function __construct(public readonly string $requestId = '')
    {
        parent::__construct("A request of the last decision has no answer ({$requestId}).");
    }

    public function type(): string
    {
        return 'b2b.request_not_answered';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
