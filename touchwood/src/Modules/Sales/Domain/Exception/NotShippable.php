<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Not approved; or holding less than is due without "may ship unpaid" (§1.5). Stock that was reduced never blocks it (answers 36, 41).
 */
final class NotShippable extends SalesError
{
    public function __construct()
    {
        parent::__construct('The order cannot be shipped yet.');
    }

    public function type(): string
    {
        return 'sales.not_shippable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
