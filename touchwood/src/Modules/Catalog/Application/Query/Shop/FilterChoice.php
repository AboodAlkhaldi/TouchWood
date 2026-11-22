<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * One choice in a category page's filters (P14): a brand, a value — a colour's with its swatch — or
 * Retail or Wholesale (its id the word, its name left to the page), and how many products it would
 * show.
 */
final readonly class FilterChoice
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $swatch,
        public int $count,
    ) {}
}
