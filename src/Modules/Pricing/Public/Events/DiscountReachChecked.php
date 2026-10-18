<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A wired store's sizes had their prices written again, and these are the fixed-amount category
 * discounts each still leaves out past the ceiling (pricing.md §6.1; sync.md §9.3 #17, #28): Sync closes
 * a "discount skipped" entry the moment the discount reaches its size again - the ceiling raised, the
 * discount changed or ended, the provider's price lowered. Sent only for a wired store, after the change
 * commits; ids only.
 */
final readonly class DiscountReachChecked implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $variantIds  the sizes written again
     * @param  list<array{discountId: string, variantId: string}>  $skipped  of them, those a discount still leaves out
     */
    public function __construct(
        public string $eventId,
        public string $storeId,
        public array $variantIds,
        public array $skipped,
        public DateTimeImmutable $occurredAt,
    ) {}
}
