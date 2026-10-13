<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\AddSale;

/**
 * A sale (pricing.md §1.3, §3): for the sizes given, or for every size of a product when only the
 * product is given; a new price or a percentage off - exactly one; a start (now when not given) and an
 * end if wanted; "always wins" or not.
 */
final readonly class AddSale
{
    /**
     * @param  list<string>  $variantIds  the sizes, all of one product; empty for every size of `productId`
     */
    public function __construct(
        public string $storeId,
        public ?string $productId,
        public array $variantIds,
        public ?string $percent,
        public ?string $price,
        public ?string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins = false,
    ) {}
}
