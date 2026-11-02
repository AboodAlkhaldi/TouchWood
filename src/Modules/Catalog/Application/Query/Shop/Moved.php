<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * An old address: the page answers with a permanent redirect to the current slug (catalog.md §1.1).
 * **Across the languages** (amendment 38) the move can stop being true - a slug of digits held in one
 * language may later be given to a new item in the other - so a browser is told to check it again.
 */
final readonly class Moved
{
    public function __construct(
        public string $slug,
        public bool $acrossLanguages = false,
    ) {}
}
