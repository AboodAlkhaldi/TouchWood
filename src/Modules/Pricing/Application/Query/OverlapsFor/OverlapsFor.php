<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

/**
 * The note before a sale is saved (pricing.md §1.3, §4.3.3 P2): the sale as the form holds it - its sizes,
 * or its product for "All Sizes" (Q39); its dates; its price or percentage off as typed, when one is typed
 * yet; "always wins" - and, when it is being changed, its id, so it is not its own overlap.
 */
final readonly class OverlapsFor
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $storeId,
        public ?string $productId,
        public array $variantIds,
        public ?string $startsAt,
        public ?string $endsAt,
        public ?string $saleId = null,
        public ?string $percent = null,
        public ?string $price = null,
        public bool $alwaysWins = false,
    ) {}
}
