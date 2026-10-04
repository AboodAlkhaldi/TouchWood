<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

final readonly class CategoryPage
{
    public function __construct(
        public string $categoryId,
        public string $name,
        public string $slug,
        public CardPage $products,
    ) {}
}
