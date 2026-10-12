<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product card (catalog.md §4.5): photo, name and labels, and "Last pieces" while a size this
 * viewer can buy and order now is ending soon (amendment 16(h)). No price, no stock, no code.
 */
#[TypeScript]
final class ShopCardData extends Data
{
    /**
     * @param  array<string, array<string, string>>|null  $photo  size slug => format => CDN URL
     * @param  list<ShopLabelData>  $labels  in the list's order
     */
    public function __construct(
        public string $productId,
        public string $name,
        public string $slug,
        public ?array $photo,
        public array $labels,
        public bool $lastPieces,
    ) {}
}
