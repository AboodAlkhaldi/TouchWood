<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product card (catalog.md §4.5): photo, name and labels, and "Last pieces" while a size this
 * viewer can buy and order now is ending soon (amendment 16(h)), and its price for this viewer (amendment
 * 35). No stock, no code.
 */
#[TypeScript]
final class ShopCardData extends Data
{
    /**
     * @param  array<string, array<string, string>>|null  $photo  size slug => format => CDN URL
     * @param  list<ShopLabelData>  $labels  in the list's order
     * @param  array{variant: string, mode: string, minimum: int}|null  $oneSize  what Add to cart adds, for one size
     * @param  list<array<string, array<string, string>>>  $morePhotos  the desktop's hover, after the card's photo
     */
    public function __construct(
        public string $productId,
        public string $name,
        public string $slug,
        public ?array $photo,
        public array $labels,
        public bool $lastPieces,
        public ?float $rating = null,
        public int $ratingCount = 0,
        public ?ShopCardPriceData $price = null,
        // The card to the design (shop-design.md §4.1, §4.3, §10.6).
        public ?string $brand = null,
        public string $unit = 'PIECE',
        public int $sizes = 0,
        public ?array $oneSize = null,
        // An individual's only: it sells wholesale to companies, which its card says (§4.1). Never set for a company or a
        // guest, whose card shows the wholesale price itself.
        public bool $sellsWholesale = false,
        public array $morePhotos = [],
        public bool $bestSeller = false,
        public bool $newArrival = false,
    ) {}
}
