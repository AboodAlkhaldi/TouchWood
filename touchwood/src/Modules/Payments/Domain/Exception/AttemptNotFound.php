<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An attempt that is not there (payments.md §7).
 */
final class AttemptNotFound extends PaymentsError
{
    public function __construct()
    {
        parent::__construct('This payment is not there.');
    }

    public function type(): string
    {
        return 'payments.attempt_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
