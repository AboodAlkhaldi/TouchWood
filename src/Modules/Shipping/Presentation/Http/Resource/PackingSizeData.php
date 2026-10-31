<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A size in the list packed by hand, or in the Add Sizes search (B11, B12): its product, code, values, photo. */
#[TypeScript]
final class PackingSizeData extends Data
{
    /**
     * @param  string  $values  its values in the page's language, "Oak · 120 cm"; empty for a product with one size
     * @param  string|null  $photo  its photo's thumbnail, or its product's
     */
    public function __construct(
        public string $variantId,
        public string $productName,
        public string $code,
        public string $values,
        public ?string $photo,
        public bool $archived,
    ) {}
}
