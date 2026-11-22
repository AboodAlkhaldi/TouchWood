<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A store switched variants off - by hand, or the import taking a product off sale (catalog.md §6.1,
 * amendment 22): Inventory's Low Stock count leaves them out. The opposite of `StoreListingChanged`.
 * Ids only, sent after the change commits.
 */
final readonly class StoreListingWithdrawn implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $eventId,
        public string $storeId,
        public array $variantIds,
        public DateTimeImmutable $occurredAt,
    ) {}
}
