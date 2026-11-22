<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One variant's row in a store (catalog.md §1.3, §4.4 S9 "Stores"): on there, sold retail, sold
 * wholesale, not available now.
 */
#[TypeScript]
final class StoreVariantData extends Data
{
    public function __construct(
        public string $variantId,
        public bool $on,
        public bool $retail,
        public bool $wholesale,
        public bool $notAvailable,
    ) {}
}
