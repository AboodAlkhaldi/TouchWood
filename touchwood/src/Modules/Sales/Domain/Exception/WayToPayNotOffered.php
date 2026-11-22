<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A way to pay not offered to this account: online to a company, a bank transfer while the store has none (§1.9).
 */
final class WayToPayNotOffered extends SalesError
{
    public function __construct()
    {
        parent::__construct('This way to pay is not offered here.');
    }

    public function type(): string
    {
        return 'sales.way_to_pay_not_offered';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
