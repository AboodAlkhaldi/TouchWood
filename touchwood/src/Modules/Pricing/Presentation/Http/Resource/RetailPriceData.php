<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A retail price as kept: the amount, its source (`STAFF`, `FILE`, `PROVIDER`) and when it last changed.
 */
#[TypeScript]
final class RetailPriceData extends Data
{
    public function __construct(
        public MoneyData $price,
        public string $source,
        public string $updatedAt,
    ) {}
}
