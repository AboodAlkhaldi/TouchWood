<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product as a picker shows it: its names, its photo's thumbnail and its codes.
 */
#[TypeScript]
final class PickedProductData extends Data
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(
        public string $productId,
        public string $nameAr,
        public ?string $nameEn,
        public ?string $photo,
        public array $codes,
    ) {}
}
