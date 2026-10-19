<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

/**
 * **What an entry or a read's record is about** (sync.md §1.6, §4.1 Y1 "What" and "Detail", §9.3 #22):
 * its kind; a size of ours - its product's names to open it there, its values ("60 cm") and code, or
 * that it no longer exists - or the provider's code for one we do not have; the sale it stopped or the
 * discount it skipped, named (Q37); a failed read and its reason (Q31); and what the provider sent, as
 * kept (`detail`: the price and stock sent, a bad value's, a code sent twice's `twice`, a stopped sale's
 * `outcome` - `left`, `ended` or `removed`).
 */
final readonly class ReportedView
{
    /**
     * @param  list<string>  $values
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public string $kind,
        public ?string $code,
        public ?string $variantId,
        public bool $sizeExists,
        public ?string $productId,
        public ?string $productNameAr,
        public ?string $productNameEn,
        public array $values,
        public ?OfferRefView $offer,
        public ?OfferRefView $discount,
        public ?string $readId,
        public ?FailureView $failure,
        public array $detail,
    ) {}
}
