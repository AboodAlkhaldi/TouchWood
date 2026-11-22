<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Places;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\ShipToCountryGuard;
use Modules\Platform\Public\Contracts\ShipToCountryGuards;
use Shared\Domain\ValueObject\StoreId;

/**
 * The guards modules register over a store's ship-to countries (shipping.md §1.3), asked by
 * `RemoveStoreCountry`.
 */
final class InMemoryShipToCountryGuards implements ShipToCountryGuards
{
    /** @var list<class-string<ShipToCountryGuard>> */
    private array $guards = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $guard): void
    {
        $this->guards[] = $guard;
    }

    public function check(StoreId $store, string $countryCode): void
    {
        foreach ($this->guards as $class) {
            $guard = $this->container->make($class);

            if (! $guard instanceof ShipToCountryGuard) {
                throw new LogicException("{$class} is registered as a ship-to country guard but is not a ShipToCountryGuard.");
            }

            $guard->check($store, $countryCode);
        }
    }
}
