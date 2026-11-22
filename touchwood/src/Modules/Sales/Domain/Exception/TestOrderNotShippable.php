<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/** A test order is never marked shipped (§9.5 #14). */
final class TestOrderNotShippable extends SalesError
{
    public function __construct()
    {
        parent::__construct('A test order is never shipped.');
    }

    public function type(): string
    {
        return 'sales.test_order_not_shippable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
