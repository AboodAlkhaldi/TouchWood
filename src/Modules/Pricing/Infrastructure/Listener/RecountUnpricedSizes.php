<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Listener;

use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Catalog\Public\Events\StoreListingWithdrawn;
use Modules\Pricing\Application\Prices\UnpricedRecounts;
use Shared\Domain\ValueObject\StoreId;

/**
 * Catalog's changes that move a store's Needs a Price count (owner Q43): sizes switched on or off in a
 * store - that store's count is counted again; a size or a product archived or restored - every store's,
 * after Catalog's change commits.
 */
final readonly class RecountUnpricedSizes
{
    public function __construct(
        private UnpricedRecounts $recounts,
    ) {}

    public function handle(): void
    {
        $this->recounts->queueAll();
    }

    public function switched(StoreListingChanged|StoreListingWithdrawn $event): void
    {
        $this->recounts->queue(StoreId::fromString($event->storeId));
    }
}
