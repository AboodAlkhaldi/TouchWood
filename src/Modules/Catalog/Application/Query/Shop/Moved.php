<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * An old address: the page answers with a permanent redirect to the current slug (catalog.md §1.1).
 */
final readonly class Moved
{
    public function __construct(
        public string $slug,
    ) {}
}
