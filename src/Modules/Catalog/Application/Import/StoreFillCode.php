<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * Where one code of a store file stands (catalog.md §1.3, amendment 6(g)): the product holding it, the
 * variant carrying it now (amendment 16(a)) - none when the product only held it once -, whether that
 * variant is archived, and whether it is on in the file's store.
 */
final readonly class StoreFillCode
{
    public function __construct(
        public string $code,
        public string $productId,
        public ?string $variantId,
        public bool $variantArchived,
        public bool $onHere,
    ) {}
}
