<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PreviewCategoryDiscount;

/**
 * What a category discount would do, before it is saved (pricing.md §1.5, §3, §4.3.3 P3): as the form
 * holds it - its dates for the note of what it overlaps, and its own id when it is being changed - and the
 * page of its products to show (`after`, the cursor of the page before).
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
        public ?string $startsAt = null,
        public ?string $endsAt = null,
        public ?string $discountId = null,
        public ?string $after = null,
    ) {}
}
