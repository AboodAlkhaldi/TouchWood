<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

final readonly class BrandPage
{
    public function __construct(
        public string $brandId,
        public string $name,
        public string $slug,
        public ?string $logoMediaId,
        public CardPage $products,
    ) {}
}
