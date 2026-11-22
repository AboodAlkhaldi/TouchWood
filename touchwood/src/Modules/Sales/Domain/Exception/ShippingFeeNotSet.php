<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A "shipping fee to be set" order is marked shipped only once staff set its fee — 0 is a real fee
 * (sales.md §9.5 #11; gap 6.2).
 */
final class ShippingFeeNotSet extends SalesError
{
    public function __construct()
    {
        parent::__construct('The shipping fee is not set yet.');
    }

    public function type(): string
    {
        return 'sales.shipping_fee_not_set';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
