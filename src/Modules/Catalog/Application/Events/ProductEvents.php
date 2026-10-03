<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Events\ProductArchived;
use Modules\Catalog\Public\Events\ProductChanged;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\ProductRestored;
use Modules\Catalog\Public\Events\VariantAdded;
use Modules\Catalog\Public\Events\VariantArchived;
use Modules\Catalog\Public\Events\VariantCodeCorrected;
use Modules\Catalog\Public\Events\VariantRestored;

/**
 * What Catalog tells the modules above about its products (catalog.md §6.1): ids only, each sent
 * after its change commits — dispatched from inside the change, never seen if it rolls back.
 */
final readonly class ProductEvents
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function madeReady(string $productId): void
    {
        $this->events->dispatch(new ProductMadeReady((string) Str::uuid(), $productId, CarbonImmutable::now()));
    }

    public function archived(string $productId): void
    {
        $this->events->dispatch(new ProductArchived((string) Str::uuid(), $productId, CarbonImmutable::now()));
    }

    public function restored(string $productId): void
    {
        $this->events->dispatch(new ProductRestored((string) Str::uuid(), $productId, CarbonImmutable::now()));
    }

    /** Its details, a variant, its photos, search words, filter values or relations. */
    public function changed(string $productId): void
    {
        $this->events->dispatch(new ProductChanged((string) Str::uuid(), $productId, CarbonImmutable::now()));
    }

    public function variantAdded(string $productId, string $variantId): void
    {
        $this->events->dispatch(new VariantAdded((string) Str::uuid(), $productId, $variantId, CarbonImmutable::now()));
    }

    public function variantArchived(string $productId, string $variantId): void
    {
        $this->events->dispatch(new VariantArchived((string) Str::uuid(), $productId, $variantId, CarbonImmutable::now()));
    }

    public function variantRestored(string $productId, string $variantId): void
    {
        $this->events->dispatch(new VariantRestored((string) Str::uuid(), $productId, $variantId, CarbonImmutable::now()));
    }

    public function codeCorrected(string $productId, string $variantId): void
    {
        $this->events->dispatch(new VariantCodeCorrected((string) Str::uuid(), $productId, $variantId, CarbonImmutable::now()));
    }
}
