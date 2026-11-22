<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A size as Pricing's lists show it (pricing.md §4.3.3): its product's names, its photo's thumbnail, its
 * values in the page's language (none: "One Size"), its code, and the marks - archived, switched on.
 */
#[TypeScript]
final class SizeData extends Data
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $productNameAr,
        public ?string $productNameEn,
        public ?string $photo,
        public string $code,
        public array $values,
        public bool $archived,
        public bool $switchedOn,
    ) {}
}
