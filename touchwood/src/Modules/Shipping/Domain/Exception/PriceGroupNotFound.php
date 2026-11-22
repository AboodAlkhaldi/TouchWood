<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A price group that is not there, or is another carrier's (§7).
 */
final class PriceGroupNotFound extends ShippingError
{
    public function __construct(public readonly string $groupId)
    {
        parent::__construct("No such price group: {$this->groupId}.");
    }

    public function type(): string
    {
        return 'shipping.price_group_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
