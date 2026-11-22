<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A store took up variants — switched them on — so Pricing and Inventory learn they need a price and
 * stock there (catalog.md §1.3, §6.1). Ids only, sent after the change commits.
 */
final readonly class StoreListingChanged implements ShouldDispatchAfterCommit
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
