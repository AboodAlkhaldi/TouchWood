<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * **One size in the note before a sale is saved** (pricing.md §4.3.3 P2): its name in the page's language;
 * its retail price; the price the sale would give it; whether that wins - today, or at the sale's start
 * when it is scheduled ("Wins today" / "Wins at its start") - or why not: `lower_price` ("A lower sale
 * runs"), `always_wins` ("An Always Wins discount runs"), `not_below_retail`, `zero` (a percentage leaving
 * 0), `no_price`; and how deep it goes against the store's ceiling (basis points; `overCeiling` - a
 * warning here, refused on Save).
 */
final readonly class OverlapSize
{
    public function __construct(
        public string $variantId,
        public string $name,
        public ?MoneyData $retail,
        public ?MoneyData $newPrice,
        public bool $wins,
        public ?string $notWinning,
        public ?int $depthBasisPoints,
        public bool $overCeiling,
    ) {}
}
