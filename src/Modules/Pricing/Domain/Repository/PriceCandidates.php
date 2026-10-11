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
     * A size's candidates in a store become exactly these.
     *
     * @param  list<Candidate>  $candidates
     */
    public function replace(StoreId $store, string $variantId, array $candidates): void;

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
