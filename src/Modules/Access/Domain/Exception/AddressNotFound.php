<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class AddressNotFound extends AccessError
{
    public function __construct(string $addressId)
    {
        parent::__construct("No address has the id \"{$addressId}\".");
    }

    public function type(): string
    {
        return 'access.address_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
