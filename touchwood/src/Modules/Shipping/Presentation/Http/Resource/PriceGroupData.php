<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of a carrier's prices: its amount and its places, by name (shipping.md §1.4, §4.1 part 2). */
#[TypeScript]
final class PriceGroupData extends Data
{
    /**
     * @param  list<PricePlaceData>  $places
     */
    public function __construct(
        public string $id,
        public MoneyData $amount,
        public array $places,
    ) {}
}
