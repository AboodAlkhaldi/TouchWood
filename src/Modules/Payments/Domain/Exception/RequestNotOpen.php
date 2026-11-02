<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Paying a request that expired, was paid or was withdrawn (payments.md §1.5, §7).
 */
final class RequestNotOpen extends PaymentsError
{
    public function __construct()
    {
        parent::__construct('This payment request is not open.');
    }

    public function type(): string
    {
        return 'payments.request_not_open';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
