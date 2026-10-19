<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product a discount reaches, with its sizes - or one it leaves out.
 */
#[TypeScript]
final class PreviewProductData extends Data
{
    /**
     * @param  list<PreviewSizeData>  $sizes
     */
    public function __construct(
        public string $productId,
        public string $nameAr,
        public ?string $nameEn,
        public array $sizes,
    ) {}
}
