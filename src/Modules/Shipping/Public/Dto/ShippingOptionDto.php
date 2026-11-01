<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Dto;

use Modules\Shipping\Public\Enums\FreeShippingReason;
use Shared\Domain\ValueObject\Money;

/**
 * One carrier that serves the address (shipping.md §2.1): its names and logo, its fee for this order -
 * free shipping applied - and why it is free, when it is. No VAT on it (§1.1 rule 8).
 */
final readonly class ShippingOptionDto
{
    public function __construct(
        public string $carrierId,
        public string $nameAr,
        public string $nameEn,
        public ?string $logoUrl,
        public Money $fee,
        public ?FreeShippingReason $freeBecause,
        /** The place's price before free shipping - what Sales keeps for an order's later edits (sales.md §9.5 #11). */
        public ?Money $price = null,
        /** The free-above amount that applies: the store-wide one while set, else the carrier's; null when neither. */
        public ?Money $freeAbove = null,
        /** Whose that amount is (`STORE`, `CARRIER`), free or not yet; null when there is none. */
        public ?FreeShippingReason $freeAboveBecause = null,
    ) {}
}
