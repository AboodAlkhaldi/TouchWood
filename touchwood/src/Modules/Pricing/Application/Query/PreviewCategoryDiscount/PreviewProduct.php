<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PreviewCategoryDiscount;

/**
 * A product a discount reaches, with its priced sizes - or one it leaves out, with none.
 */
final readonly class PreviewProduct
{
    /**
     * @param  list<PreviewSize>  $sizes
     */
    public function __construct(
        public string $productId,
        public string $nameAr,
        public ?string $nameEn,
        public array $sizes,
    ) {}
}
