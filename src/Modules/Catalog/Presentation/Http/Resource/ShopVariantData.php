<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A variant on sale here for this viewer, as its page shows it once chosen (catalog.md §4.5).
 * Matched in the browser for display only; Sales resolves the variant on the server.
 */
#[TypeScript]
final class ShopVariantData extends Data
{
    /**
     * @param  array<string, string>  $values  attribute id => value id
     * @param  list<array<string, array<string, string>>>  $photos  its own photos
     * @param  list<ShopDetailData>  $details
     */
    public function __construct(
        public string $id,
        public array $values,
        public bool $sellsRetail,
        public bool $sellsWholesale,
        public bool $lastPieces,
        public array $photos,
        public array $details,
        public ?int $weightGrams,
        public ?int $lengthMm,
        public ?int $widthMm,
        public ?int $heightMm,
    ) {}
}
