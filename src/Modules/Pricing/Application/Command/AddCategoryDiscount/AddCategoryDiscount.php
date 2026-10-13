<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\AddCategoryDiscount;

/**
 * Add a category discount in a store (pricing.md §1.5, §3): a percentage or a fixed amount - exactly
 * one - on retail lines, wholesale lines or both (`RETAIL`, `WHOLESALE`, `BOTH`), from its start (now
 * when empty), until its end if given; "always wins" or not; products left out by id.
 */
final readonly class AddCategoryDiscount
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
        public ?string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins = false,
        public array $leftOut = [],
    ) {}
}
