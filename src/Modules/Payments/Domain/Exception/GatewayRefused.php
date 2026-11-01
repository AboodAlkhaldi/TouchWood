<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The gateway answered and said no (payments.md §7). Its own words stay out of the answer a page gets:
 * they are in the call's log, and `$reason` is for staff's screens only.
 */
final class GatewayRefused extends PaymentsError
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The payment gateway refused: '.$this->reason);
    }

    public function type(): string
    {
        return 'payments.gateway_refused';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
