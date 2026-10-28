<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * A module's say over taking a country off a store's ship-to list (shipping.md §1.3, §9.3 #9):
 * asked inside the change's transaction, after the store's row for the country is locked. Shipping
 * refuses while the store still has a carrier there.
 */
interface ShipToCountryGuard
{
    /**
     * @throws DomainError to hold the change back
     */
    public function check(StoreId $store, string $countryCode): void;
}
