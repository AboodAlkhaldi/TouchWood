<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use DateTimeImmutable;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Shared\Domain\ValueObject\StoreId;

/**
 * The materialized candidates every price is resolved from (pricing.md §1.6, §5
 * `pricing.price_candidates`): rewritten per size by every change, inside its transaction, so a cart
 * is priced by one indexed read.
 */
interface PriceCandidates
{
    /**
     * Each size's candidates in a store become exactly these - none, for a size given an empty list.
     *
     * @param  array<string, list<Candidate>>  $candidates  variant id => its candidates
     */
    public function replace(StoreId $store, array $candidates): void;

    /**
     * @param  list<string>  $variantIds
     * @return array<string, list<Candidate>> variant id => its candidates there, every mode
     */
    public function forVariants(StoreId $store, array $variantIds): array;

    /**
     * @param  list<StoreId>  $stores
     * @param  list<string>  $variantIds
     * @return array<string, array<string, list<Candidate>>> store id => variant id => its candidates there
     */
    public function inStores(array $stores, array $variantIds): array;

    /**
     * Every candidate of the sizes a sale or a category discount reaches on a retail line at that moment -
     * the sizes that may be on sale then (the Prices screen's **On Sale Now**, §4.3.3 P1).
     *
     * @return array<string, list<Candidate>> variant id => its candidates there, every mode
     */
    public function reducedAt(StoreId $store, DateTimeImmutable $at): array;

    /**
     * @return list<string> every size holding a candidate in that store, for the repair job
     */
    public function variantsWithCandidates(StoreId $store): array;
}
