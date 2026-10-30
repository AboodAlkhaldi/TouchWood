<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A carrier's prices changed since the page read them (shipping.md §1.4, §8 #3): two staff saving one
 * carrier at once - the first wins, the second is told to reload rather than writing over it.
 */
final class PricesChanged extends ShippingError
{
    public function __construct(public readonly string $carrierId)
    {
        parent::__construct("The prices of carrier {$this->carrierId} changed meanwhile.");
    }

    public function type(): string
    {
        return 'shipping.prices_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
