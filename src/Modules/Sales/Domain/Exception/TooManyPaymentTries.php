<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** Over the limits that only stop abuse (payments.md §1.8): nothing changed. */
final class TooManyPaymentTries extends SalesError
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many payment tries.');
    }

    public function type(): string
    {
        return 'sales.too_many_payment_tries';
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
