<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * One size in a campaign's preview (pricing.md §9.6 #8): its code, its retail price in the store, the
 * price the campaign would give it and how deep that goes (basis points) - or, when the campaign skips
 * it, why (`skipped`): `archived` (the size or its product), `no_price` (no retail price in the store),
 * `zero_or_below` (a percentage leaving 0), `not_below_retail` (a fixed price at or above the retail
 * price: it would never win), `past_ceiling` (deeper than the store's ceiling: refused on saving, or
 * skipped in a wired store, §9.6 #4-5).
 */
final readonly class CampaignPreviewSizeDto
{
    public function __construct(
        public string $variantId,
        public string $code,
        public ?Money $retail,
        public ?Money $newPrice,
        public ?int $depthBasisPoints,
        public ?string $skipped,
    ) {}
}
