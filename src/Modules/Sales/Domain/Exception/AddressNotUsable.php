<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * No address in this store, or its format no longer accepted (§1.2).
 */
final class AddressNotUsable extends SalesError
{
    public function __construct()
    {
        parent::__construct('The address cannot be used for this order.');
    }

    public function type(): string
    {
        return 'sales.address_not_usable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
