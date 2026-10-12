<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How a product is sold here, as its page tells this viewer (P17); an individual is told the retail
 * side only.
 */
#[TypeScript]
final class ShopSellingData extends Data
{
    public function __construct(
        public int $retailMinimum,
        public ?int $retailMaximum,
        public ?int $wholesaleMinimum,
        public ?int $wholesaleMaximum,
    ) {}
}
