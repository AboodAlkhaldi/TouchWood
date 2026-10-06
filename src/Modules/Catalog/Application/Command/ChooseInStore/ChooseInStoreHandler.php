<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ChooseInStore;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Listing\StoreListingChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Model\Variant;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Public\Enums\ProductStage;
use Shared\Application\Unauthorized;

/**
 * **A store's choice** (catalog.md §1.3, §3): `catalog.listing.choose` in that store — a whole
 * product or single variants, switched on or off. The whole product is every variant not archived;
 * a variant added later is chosen nowhere until each store chooses it. **Only a ready product's
 * variants, not archived, are switched on**; switching off is always allowed and keeps the rows, so
 * choosing again brings back how the variant sold. A variant first chosen sells retail only
 * (amendment 4(e)). The variants a store takes up are sent as `StoreListingChanged` (§6.1).
 */
final readonly class ChooseInStoreHandler
{
    public const string PERMISSION = CatalogPermissions::LISTING_CHOOSE;

    public function __construct(
        private StoreListingChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
        private StoreListingRepository $listings,
        private ProductEvents $events,
        private ListingRows $listingRows,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ProductNotFound|Unauthorized|VariantNotFound
     */
    public function handle(ChooseInStore $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $named = $command->variantIds === null ? null : self::ids($command->variantIds);

        $this->change->run(function () use ($command, $store, $named): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $variants = [];

            foreach ($this->variants->ofProduct($product->id()) as $variant) {
                $variants[$variant->id()] = $variant;
            }

            foreach ($named ?? [] as $variantId) {
                if (! isset($variants[$variantId])) {
                    throw new VariantNotFound($variantId);
                }
            }

            $ids = $named ?? array_keys(array_filter($variants, static fn (Variant $variant): bool => ! $command->active || ! $variant->isArchived()));

            if ($command->active) {
                if ($product->stage() !== ProductStage::Ready) {
                    throw new InvalidCatalogAttribute('product', 'a ready product');
                }

                foreach ($ids as $variantId) {
                    if ($variants[$variantId]->isArchived()) {
                        throw new InvalidCatalogAttribute('variants', 'variants not archived');
                    }
                }
            }

            $listing = $this->listings->of($store->value, $product->id());
            $wasActive = $listing->activeVariantIds();
            $listing->choose(array_map('strval', $ids), $command->active);
            $entry = ListAudit::changed('listing', 'chosen', $product->id(), $listing->pullChanges(), $listing->snapshot(), $store->value);

            if ($entry === null) {
                return [null, []];
            }

            $this->listings->save($listing);
            $this->listingRows->refresh([$product->id()]);
            $takenUp = array_values(array_diff($listing->activeVariantIds(), $wasActive));

            if ($takenUp !== []) {
                $this->events->storeListingChanged($store->value, $takenUp);
            }

            return [null, [$entry]];
        });
    }

    /**
     * The variants named, as the request sent them: ids only, each once.
     *
     * @param  array<array-key, mixed>  $variantIds
     * @return list<string>
     *
     * @throws InvalidCatalogAttribute
     */
    private static function ids(array $variantIds): array
    {
        $ids = [];

        foreach ($variantIds as $variantId) {
            if (! is_string($variantId)) {
                throw new InvalidCatalogAttribute('variants', 'variants, by their ids');
            }

            $ids[strtolower($variantId)] = true;
        }

        return array_keys($ids);
    }
}
