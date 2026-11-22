<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

/**
 * One entry of a coupon's include or exclude list (promotions.md §1.2, §4.1): what it names, as
 * Catalog names it now — none for an entry Catalog no longer has — the path to a category in both
 * languages ("Kitchens › Drawers"), and whether it is still sold: an archived product, or a category
 * or brand switched off, stays on the list and matches nothing (answer 22), shown "Not sold now".
 */
final readonly class RuleView
{
    public function __construct(
        public string $kind,
        public string $target,
        public string $targetId,
        public ?string $nameAr,
        public ?string $nameEn,
        public ?string $pathAr = null,
        public ?string $pathEn = null,
        public bool $sold = false,
    ) {}
}
