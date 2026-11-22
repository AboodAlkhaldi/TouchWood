<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Taking a country off a store's ship-to list while the store has carriers there (§1.3; Platform asks through its hook).
 */
final class CountryHasCarriers extends ShippingError
{
    public function __construct(public readonly string $countryCode, public readonly int $carriers)
    {
        parent::__construct("The store still has {$this->carriers} carrier(s) in {$this->countryCode}.");
    }

    public function type(): string
    {
        return 'shipping.country_has_carriers';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['country' => $this->countryCode, 'carriers' => $this->carriers];
    }
}
