<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The account may not order here: not active, its email or phone not verified, a deletion pending, a company not approved in this store, or the store off (§1.2).
 */
final class MayNotOrder extends SalesError
{
    public function __construct()
    {
        parent::__construct('This account may not order in this store.');
    }

    public function type(): string
    {
        return 'sales.may_not_order';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
