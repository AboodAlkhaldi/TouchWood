<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who may read Pricing's screens in a store (pricing.md §3): whoever holds any of the jobs named there -
 * the three, unless a read names its own (owner, 2026-10-08). A store that does not exist is invalid
 * input, asked only of a reader allowed there, so it tells no one else anything.
 */
final readonly class ReadAccess
{
    /** Any of Pricing's three jobs. */
    public const array ANY_OF_THE_THREE = [
        PricingPermissions::PRICE_EDIT,
        PricingPermissions::WHOLESALE_EDIT,
        PricingPermissions::CATEGORY_DISCOUNT_MANAGE,
    ];

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /**
     * @param  list<string>  $permissions  any one of which is enough
     *
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function authorize(string $storeId, array $permissions = self::ANY_OF_THE_THREE): StoreDto
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized($permissions[0] ?? PricingPermissions::PRICE_EDIT);
        }

        foreach ($permissions as $permission) {
            $stores = $this->authorizer->storesWith($permission);

            if ($stores === null || in_array($store->value, array_map(static fn (StoreId $id): string => $id->value, $stores), true)) {
                $found = $this->platform->store($store) ?? throw new InvalidPricingInput('store');

                // A store that is off is offered only to whoever may switch stores (platform.md §9.10).
                if (! $found->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
                    throw new StoreOff;
                }

                return $found;
            }
        }

        throw new Unauthorized($permissions[0] ?? PricingPermissions::PRICE_EDIT);
    }
}
