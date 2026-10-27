<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The cart's next gift (sales.md §4.1 S4; §4.2 B12): the level's amount, and about how much more the goods
 * need - measured after a percent coupon, so said as "about".
 */
#[TypeScript]
final class CartGiftData extends Data
{
    public function __construct(
        public MoneyData $amount,
        public MoneyData $missing,
    ) {}
}
