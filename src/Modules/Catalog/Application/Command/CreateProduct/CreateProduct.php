<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\CreateProduct;

/**
 * A new product, a draft (catalog.md §1.1, §4.1): its Arabic name at least (amendment 3(g)), from
 * the store the staff member works in, which must be on. It starts on the default brand unless
 * another is given; slugs left empty are made from the names.
 */
final readonly class CreateProduct
{
    public function __construct(
        public string $storeId,
        public string $nameAr,
        public ?string $nameEn = null,
        public ?string $brandId = null,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
    ) {}
}
