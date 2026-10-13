<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A label on a card or a product page, in Geist Badge's tone (catalog.md §1.8).
 */
#[TypeScript]
final class ShopLabelData extends Data
{
    public function __construct(
        public string $name,
        public string $tone,
    ) {}
}
