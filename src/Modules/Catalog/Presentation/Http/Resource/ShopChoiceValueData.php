<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A value a product page offers to pick; one no variant on sale holds cannot be picked.
 */
#[TypeScript]
final class ShopChoiceValueData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $swatch,
        public bool $onSale,
    ) {}
}
