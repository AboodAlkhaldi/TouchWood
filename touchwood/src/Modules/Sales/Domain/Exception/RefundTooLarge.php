<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A refund above what the order holds (§1.9).
 */
final class RefundTooLarge extends SalesError
{
    public function __construct()
    {
        parent::__construct('The refund is larger than what the order holds.');
    }

    public function type(): string
    {
        return 'sales.refund_too_large';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
