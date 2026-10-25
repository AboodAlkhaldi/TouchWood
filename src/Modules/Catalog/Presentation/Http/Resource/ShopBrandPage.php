<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A brand's page in the shop (catalog.md §4.5): its logo, name and description, then its products
 * as a category page shows them.
 */
#[TypeScript]
final class ShopBrandPage extends Data
{
    /**
     * @param  array<string, array<string, string>>|null  $logo  size slug => format => CDN URL
     * @param  array<string, mixed>  $description  the structured text; empty when it has none
     * @param  list<ShopCardData>  $products
     * @param  array<string, array<string, array<string, mixed>>>  $cardFacts  product id => `{module}.{key}` => what that module adds to its card (amendment 32), read for this viewer, never kept
     */
    public function __construct(
        public string $name,
        public string $slug,
        public ?array $logo,
        public array $description,
        public array $products,
        public ?string $next,
        public ShopAddressData $address,
        public array $cardFacts = [],
    ) {}
}
