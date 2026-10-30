<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SetCarrierFreeAbove;

/**
 * A carrier's own free-shipping amount (shipping.md §1.5), in the currency's smallest unit: from this
 * goods total its fee is 0; null for never. The store-wide amount, while set, overrides it.
 */
final readonly class SetCarrierFreeAbove
{
    public function __construct(
        public string $storeId,
        public string $carrierId,
        public ?int $amountMinor,
    ) {}
}
