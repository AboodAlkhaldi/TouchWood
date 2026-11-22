<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * Where a module registers a guard over a store's ship-to countries, at boot.
 */
interface ShipToCountryGuards
{
    /**
     * @param  class-string<ShipToCountryGuard>  $guard  resolved from the container when a country is taken off
     */
    public function register(string $guard): void;
}
