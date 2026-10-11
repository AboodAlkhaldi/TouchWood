<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * One line of a variant's details on its page (catalog.md §1.7): an informational attribute's name
 * and the variant's value, in the page's language, with its unit.
 */
final readonly class VariantDetail
{
    public function __construct(
        public string $name,
        public string $value,
    ) {}
}
