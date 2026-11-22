<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One size an offer covers, as View Offer shows it.
 */
#[TypeScript]
final class SaleSizeData extends Data
{
    public function __construct(
        public string $variantId,
        public string $name,
        public string $code,
        public bool $archived,
        public bool $switchedOn,
        public ?MoneyData $retail,
        public ?MoneyData $salePrice,
    ) {}
}
