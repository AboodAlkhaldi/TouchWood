<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** Over the limit on opening sessions (§1.8). A session reused is not counted. */
final class TooManyPaymentSessions extends PaymentsError
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many payment sessions opened.');
    }

    public function type(): string
    {
        return 'payments.too_many_payment_sessions';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::TooMany;
    }

    public function context(): array
    {
        return ['minutes' => max(1, (int) ceil($this->retryAfterSeconds / 60))];
    }
}
