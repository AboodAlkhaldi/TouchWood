<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Public\Events\CategoryMoved;
use Modules\Catalog\Public\Events\ProductArchived;
use Modules\Catalog\Public\Events\ProductChanged;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\ProductRestored;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Catalog\Public\Events\VariantAdded;
use Modules\Catalog\Public\Events\VariantArchived;
use Modules\Catalog\Public\Events\VariantCodeCorrected;
use Modules\Catalog\Public\Events\VariantRestored;

/**
 * What Catalog tells the modules above about its products (catalog.md §6.1): ids only, each sent
 * after its change commits — dispatched from inside the change, never seen if it rolls back. **Only
 * for a product that has been ready** (amendment 3(m)): a draft, and a draft archived when abandoned,
 * is Catalog's alone. Each is given the product as the change left it. And one about a category,
 * `CategoryMoved`, which names no product and so is always sent (amendment 16(i)).
 */
final readonly class ProductEvents
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    /** Always sent: the category was put under another parent, so what is below the ones above it changed. */
    public function categoryMoved(string $categoryId): void
    {
        $this->events->dispatch(new CategoryMoved((string) Str::uuid(), $categoryId, CarbonImmutable::now()));
    }

    /** Always sent: the product has just been made ready. */
    public function madeReady(Product $product): void
    {
        $this->events->dispatch(new ProductMadeReady((string) Str::uuid(), $product->id(), CarbonImmutable::now()));
    }

    public function archived(Product $product): void
    {
        if (! $product->hasBeenReady()) {
            return;
        }

        $this->events->dispatch(new ProductArchived((string) Str::uuid(), $product->id(), CarbonImmutable::now()));
    }

    public function restored(Product $product): void
    {
        if (! $product->hasBeenReady()) {
            return;
        }

        $this->events->dispatch(new ProductRestored((string) Str::uuid(), $product->id(), CarbonImmutable::now()));
    }

    /** Its details, a variant, its photos, search words, filter values or relations. */
    public function changed(Product $product): void
    {
        if (! $product->hasBeenReady()) {
            return;
        }

        $this->events->dispatch(new ProductChanged((string) Str::uuid(), $product->id(), CarbonImmutable::now()));
    }

    /**
     * A store took up these variants — only a ready product's are ever switched on.
     *
     * @param  list<string>  $variantIds
     */
    public function storeListingChanged(string $storeId, array $variantIds): void
    {
        $this->events->dispatch(new StoreListingChanged((string) Str::uuid(), $storeId, $variantIds, CarbonImmutable::now()));
    }

    public function variantAdded(Product $product, string $variantId): void
    {
        if (! $product->hasBeenReady()) {
            return;
        }

        $this->events->dispatch(new VariantAdded((string) Str::uuid(), $product->id(), $variantId, CarbonImmutable::now()));
    }

    public function variantArchived(Product $product, string $variantId): void
    {
        if (! $product->hasBeenReady()) {
            return;
        }

        $this->events->dispatch(new VariantArchived((string) Str::uuid(), $product->id(), $variantId, CarbonImmutable::now()));
    }

    public function variantRestored(Product $product, string $variantId): void
    {
        if (! $product->hasBeenReady()) {
            return;
        }

        $this->events->dispatch(new VariantRestored((string) Str::uuid(), $product->id(), $variantId, CarbonImmutable::now()));
    }

    public function codeCorrected(Product $product, string $variantId): void
    {
        if (! $product->hasBeenReady()) {
            return;
        }

        $this->events->dispatch(new VariantCodeCorrected((string) Str::uuid(), $product->id(), $variantId, CarbonImmutable::now()));
    }
}
