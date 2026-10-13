<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PreviewCategoryDiscount;

/**
 * What a category discount would do, before it is saved (pricing.md §1.5, §3): as the form holds it.
 */
final readonly class PreviewCategoryDiscount
{
    /**
     * @param  list<string>  $leftOut
     */
    public function __construct(
        public string $storeId,
        public string $categoryId,
        public ?string $percent,
        public ?string $amount,
        public string $lines,
        public array $leftOut = [],
    ) {}
}
