<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Modules\Catalog\Public\Enums\SaleMode;

/**
 * A variant in one store, for Sales (catalog.md §2.1): whether the store switched it on, whether it
 * can be ordered now (§1.3 until Inventory pushes it, §2.2 — never while its product is hidden with
 * its category or brand, amendment 5(j)), whether it — or its product — is "Not available now"
 * there, how it sells, and its product's limits for each mode there. A variant the store never
 * chose is switched off, sells in no mode and carries the starting limits; one switched off keeps
 * the modes it had, for the day it is switched on again (amendment 4(l)).
 */
final readonly class StoreVariantDto
{
    /**
     * @param  list<SaleMode>  $saleModes
     */
    public function __construct(
        public string $storeId,
        public string $variantId,
        public string $productId,
        public bool $isActive,
        public bool $orderable,
        public bool $notAvailableNow,
        public array $saleModes,
        public int $retailMinimum,
        public ?int $retailMaximum,
        public ?int $wholesaleMinimum,
        public ?int $wholesaleMaximum,
    ) {}
}
