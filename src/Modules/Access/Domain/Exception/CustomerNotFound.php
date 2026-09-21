<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class CustomerNotFound extends AccessError
{
    public function __construct(string $customerId)
    {
        parent::__construct("No customer has the id \"{$customerId}\".");
    }

    public function type(): string
    {
        return 'access.customer_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
