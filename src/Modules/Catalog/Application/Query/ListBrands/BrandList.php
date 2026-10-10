<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListBrands;

use Modules\Catalog\Application\Query\Lists\BrandRow;

/**
 * The brands, and whether the reader may change them — the job with All stores (§1.6).
 */
final readonly class BrandList
{
    /**
     * @param  list<BrandRow>  $brands
     */
    public function __construct(
        public array $brands,
        public bool $mayChange,
    ) {}
}
