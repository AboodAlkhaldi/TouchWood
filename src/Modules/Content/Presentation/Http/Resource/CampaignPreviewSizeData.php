<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One size in a run's live preview (pricing.md §9.6 #8): its code, retail price, the price the campaign gives it and
 * how deep, or why it is skipped.
 */
#[TypeScript]
final class CampaignPreviewSizeData extends Data
{
    public function __construct(
        public string $variantId,
        public string $code,
        public ?string $retail,
        public ?string $newPrice,
        public ?int $depthBp,
        public ?string $skipped,
    ) {}
}
