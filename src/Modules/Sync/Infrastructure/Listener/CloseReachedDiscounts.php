<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Listener;

use Modules\Pricing\Public\Events\DiscountReachChecked;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * A "discount skipped" entry closes the moment the discount reaches its size again (owner, 2026-10-18,
 * sync.md §9.3 #28): Pricing wrote the size's prices again - the ceiling raised, the discount changed
 * or ended, the provider's price lowered - and no fixed amount leaves it out now. Closed by `PRICING`,
 * after Pricing's change commits; an error is reported, never shown to staff as their change failing.
 */
final readonly class CloseReachedDiscounts
{
    public function __construct(
        private ReportEntries $entries,
    ) {}

    public function handle(DiscountReachChecked $event): void
    {
        try {
            $store = StoreId::fromString($event->storeId);

            // Most changes find nothing skipped: one look, and nothing more.
            if (! $this->entries->hasOpen($store, ReportKind::DiscountSkipped)) {
                return;
            }

            $reached = array_values(array_diff($event->variantIds, array_column($event->skipped, 'variantId')));
            $this->entries->close($store, ReportKind::DiscountSkipped, [], $reached, 'PRICING');
        } catch (Throwable $error) {
            report($error);
        }
    }
}
