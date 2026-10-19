<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One wholesale band: "From 10: 95.00".
 */
#[TypeScript]
final class BandData extends Data
{
    public function __construct(
        public int $fromQuantity,
        public MoneyData $price,
    ) {}
}
