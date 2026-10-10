<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ProductsReached;

/**
 * The products a brand's or a category's deactivation would reach (catalog.md §1.5, §1.6, §4.4 S1,
 * S2), for the fates dialog.
 */
final readonly class ProductsReached
{
    public const string BRAND = 'brand';

    public const string CATEGORY = 'category';

    public function __construct(
        public string $kind,
        public string $id,
    ) {}
}
