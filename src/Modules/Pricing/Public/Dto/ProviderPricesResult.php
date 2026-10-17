<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

/**
 * What `ProviderPrices::apply` did with a read's prices (pricing.md §2.6), sizes by the variant id as
 * given: those whose price changed, and of them those switched off in the store or of a draft product;
 * the sales stopped on a size (`left` - the sale goes on without it, `ended`, `removed`) and the
 * category discounts now skipping a size; those left as they are for Sync to report - archived, not
 * found, a price that is not one. `wired` false: the store was not wired when the prices arrived, or
 * stopped being so while they were written, and nothing more was.
 */
final readonly class ProviderPricesResult
{
    /**
     * @param  list<string>  $changed
     * @param  list<string>  $offOrDraft
     * @param  list<array{saleId: string, variantId: string, outcome: 'left'|'ended'|'removed'}>  $salesStopped
     * @param  list<array{discountId: string, variantId: string}>  $discountsSkipped
     * @param  list<string>  $archived
     * @param  list<string>  $notFound
     * @param  list<string>  $invalid
     */
    public function __construct(
        public bool $wired,
        public array $changed = [],
        public array $offOrDraft = [],
        public array $salesStopped = [],
        public array $discountsSkipped = [],
        public array $archived = [],
        public array $notFound = [],
        public array $invalid = [],
    ) {}
}
