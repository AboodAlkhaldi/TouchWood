<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The shop's search results (catalog.md §4.5, P16): how many, the cards 48 at a time.
 */
#[TypeScript]
final class ShopSearchPage extends Data
{
    /**
     * @param  list<ShopCardData>  $products
     * @param  array<string, array<string, array<string, mixed>>>  $cardFacts  product id => `{module}.{key}` => what that module adds to its card (amendment 32), read for this viewer, never kept
     */
    public function __construct(
        public string $query,
        public int $total,
        public array $products,
        public ?string $next,
        public ShopAddressData $address,
        public array $cardFacts = [],
    ) {}
}
