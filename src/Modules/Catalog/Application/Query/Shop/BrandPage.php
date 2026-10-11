<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A brand's page (catalog.md §4.5): its logo, name and description, its slug here and in the other
 * language (P19), and its products as a category page shows them.
 */
final readonly class BrandPage
{
    /**
     * @param  array<string, mixed>  $description  the structured text (§1.1); empty when it has none
     */
    public function __construct(
        public string $brandId,
        public string $name,
        public string $slug,
        public ?string $logoMediaId,
        public CardPage $products,
        public ?string $otherSlug = null,
        public array $description = [],
    ) {}
}
