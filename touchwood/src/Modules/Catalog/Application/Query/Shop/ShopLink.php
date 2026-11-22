<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A category or a brand a shop page links to, by its name and its current slug in the page's
 * language, and its id where the read had it (a category's way up: what the slots above its list
 * are asked with, amendment 41(a)).
 */
final readonly class ShopLink
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $id = null,
    ) {}
}
