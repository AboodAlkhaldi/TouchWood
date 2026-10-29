<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A carrier that is not there, or is another store's (§7).
 */
final class CarrierNotFound extends ShippingError
{
    public function __construct(public readonly string $carrierId)
    {
        parent::__construct("No such carrier: {$this->carrierId}.");
    }

    public function type(): string
    {
        return 'shipping.carrier_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
