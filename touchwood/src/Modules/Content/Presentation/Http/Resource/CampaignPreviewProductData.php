<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product a run's preview reaches, with its sizes.
 */
#[TypeScript]
final class CampaignPreviewProductData extends Data
{
    /**
     * @param  list<CampaignPreviewSizeData>  $sizes
     */
    public function __construct(
        public string $productId,
        public string $nameAr,
        public ?string $nameEn,
        public array $sizes,
    ) {}
}
