<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A wholesale band as the shop's product page shows it (catalog.md amendment 35): from `minQuantity`
 * pieces on a wholesale line, one piece costs `unit` now — the band, or a sale or discount below it —
 * without VAT, written by the server in the store's currency.
 */
#[TypeScript]
final class ShopBandData extends Data
{
    public function __construct(
        public int $minQuantity,
        public MoneyData $unit,
    ) {}
}
