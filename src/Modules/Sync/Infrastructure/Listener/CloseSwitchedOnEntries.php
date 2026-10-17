<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Listener;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\ReportEntries;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * An off or draft size the provider priced is listed until staff switch it on (sync.md §1.6): when
 * Catalog switches sizes on in a store, or makes their product ready, the entries of sizes now on and
 * ready close - by Catalog. After Catalog's change commits (its events wait for it); an error is
 * reported, never shown to staff as their change failing.
 */
final readonly class CloseSwitchedOnEntries
{
    public function __construct(
        private CatalogApi $catalog,
        private Connections $connections,
        private ReportEntries $entries,
    ) {}

    public function switchedOn(StoreListingChanged $event): void
    {
        $this->close(StoreId::fromString($event->storeId), $event->variantIds);
    }

    public function madeReady(ProductMadeReady $event): void
    {
        $sizes = $this->catalog->variantIdsOf($event->productId);

        foreach ($this->connections->wiredStores() as $store) {
            $this->close(StoreId::fromString($store), $sizes);
        }
    }

    /**
     * @param  list<string>  $variantIds
     */
    private function close(StoreId $store, array $variantIds): void
    {
        try {
            $on = array_intersect($variantIds, $this->catalog->switchedOnVariantIds($store));
            $variants = $this->catalog->variants(array_values($on));
            $products = $this->catalog->products(array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, array_values($variants)))));
            $ready = array_keys(array_filter($variants, static fn ($variant): bool => ($products[$variant->productId] ?? null)?->stage === ProductStage::Ready));

            if ($ready !== []) {
                $this->entries->closeSwitchedOn($store, array_map('strval', $ready));
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}
