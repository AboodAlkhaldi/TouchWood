<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PriceViews;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * **What a line pays now, and why** (pricing.md §1.6, §4.3.3 P1 "Today"): the price of one piece; the
 * line's normal price struck through above it only while it is lower (`before`); the kind that won -
 * `BASE`, `SALE`, `CATEGORY` (the panel's "Discount") or, on a wholesale line, `QUANTITY`; whether the
 * winner always wins; the sale or discount it comes from; and when that price stops applying (ISO 8601,
 * none when nothing ends it) - "until 30 Oct".
 */
final readonly class TodayPrice
{
    public function __construct(
        public MoneyData $price,
        public ?MoneyData $before,
        public string $why,
        public bool $alwaysWins,
        public ?string $sourceId,
        public ?string $endsAt,
    ) {}
}
