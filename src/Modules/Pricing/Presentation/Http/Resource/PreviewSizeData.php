<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One size in a discount's preview: retail to new price, or skipped and why.
 */
#[TypeScript]
final class PreviewSizeData extends Data
{
    public function __construct(
        public string $variantId,
        public string $code,
        public string $name,
        public MoneyData $retail,
        public ?MoneyData $newPrice,
        public ?MoneyData $difference,
        public ?string $skipped,
    ) {}
}
