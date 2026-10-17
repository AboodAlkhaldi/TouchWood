<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\ChangeCategoryDiscount;

/**
 * Change a category discount (pricing.md §3, §4.1): a scheduled one in full; a running one only its end,
 * every other value as it is.
 */
final readonly class ChangeCategoryDiscount
{
    /**
     * @param  list<string>  $leftOut
     */
    public function __construct(
        public string $discountId,
        public string $categoryId,
        public ?string $percent,
        public ?string $amount,
        public string $lines,
        public ?string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins = false,
        public array $leftOut = [],
    ) {}
}
