<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * A product picked for another's "You May Also Like" or "Goes With" (catalog.md §1.10, §4.4 S9).
 */
final readonly class RelatedRow
{
    /**
     * @param  string  $kind  `RELATED` or `GOES_WITH`
     * @param  list<string>  $codes
     */
    public function __construct(
        public string $kind,
        public string $productId,
        public string $nameAr,
        public ?string $nameEn,
        public array $codes,
        public string $stage,
    ) {}
}
