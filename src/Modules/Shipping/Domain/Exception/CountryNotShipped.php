<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A carrier for a country the store does not ship to (§7).
 */
final class CountryNotShipped extends ShippingError
{
    public function __construct(public readonly string $countryCode)
    {
        parent::__construct("The store does not ship to {$this->countryCode}.");
    }

    public function type(): string
    {
        return 'shipping.country_not_shipped';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['country' => $this->countryCode];
    }
}
