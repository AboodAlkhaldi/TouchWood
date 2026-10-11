<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

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
     * @return list<string> every size holding a candidate in that store, for the repair job
     */
    public function variantsWithCandidates(StoreId $store): array;
}
