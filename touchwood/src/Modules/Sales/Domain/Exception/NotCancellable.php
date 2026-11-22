<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Shipped, or already cancelled, rejected or finished (§1.6).
 */
final class NotCancellable extends SalesError
{
    public function __construct()
    {
        parent::__construct('The order can no longer be cancelled.');
    }

    public function type(): string
    {
        return 'sales.not_cancellable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
