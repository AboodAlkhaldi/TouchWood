<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewPrices;

/**
 * One price a size may be sold at (§1.6's candidates): its line (`RETAIL`, `WHOLESALE`), from what
 * quantity, the amount in minor units, its kind (`BASE`, `SALE`, `CATEGORY`, `QUANTITY`), "always wins",
 * its dates as ISO 8601, and the sale or discount it comes from.
 */
final readonly class PriceLine
{
    public function __construct(
        public string $mode,
        public int $fromQuantity,
        public int $amountMinor,
        public string $kind,
        public bool $alwaysWins,
        public ?string $startsAt,
        public ?string $endsAt,
        public ?string $sourceId,
    ) {}
}
