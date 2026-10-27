<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A size on a wholesale line, as the shop's product page shows it (catalog.md amendment 35): `price`,
 * what one piece costs now on a wholesale line below any band (its base price, or a sale or discount
 * below it), and its `bands`, lowest start first — none when it has none. Without VAT.
 */
#[TypeScript]
final class ShopWholesaleSizeData extends Data
{
    /**
     * @param  list<ShopBandData>  $bands
     */
    public function __construct(
        public MoneyData $price,
        public array $bands,
    ) {}
}
