<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One size in the offer dialog's note: its new price, whether it wins, why not, its depth against the
 * ceiling.
 */
#[TypeScript]
final class OverlapSizeData extends Data
{
    public function __construct(
        public string $variantId,
        public string $name,
        public ?MoneyData $retail,
        public ?MoneyData $newPrice,
        public bool $wins,
        public ?string $notWinning,
        public ?int $depthBasisPoints,
        public bool $overCeiling,
    ) {}
}
