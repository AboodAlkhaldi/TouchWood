<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A place a carrier cannot be priced by (§1.4, §7): not one of Platform's, of another country than the
 * carrier's, or retired (it has left every picker - §9.3 #4) and not already in this group.
 */
final class PlaceNotInCountry extends ShippingError
{
    public function __construct(public readonly string $placeId, public readonly string $countryCode)
    {
        parent::__construct("The place {$this->placeId} is not one in use in {$this->countryCode}.");
    }

    public function type(): string
    {
        return 'shipping.place_not_in_country';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['place' => $this->placeId, 'country' => $this->countryCode];
    }
}
