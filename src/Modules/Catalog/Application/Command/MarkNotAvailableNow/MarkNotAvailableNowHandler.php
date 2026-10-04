<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MarkNotAvailableNow;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Listing\StoreListingChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\NotChosenInStore;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;

/**
 * **"Not available now"** (catalog.md §1.3, §3; handoff §9.2's `force_unavailable`): its own
 * permission, `catalog.listing.unavailable`, in that store — a recall, a pricing error, a legal hold.
 * On the whole product it covers every variant, later ones too; on a variant, that variant only;
 * nothing in another store, and it never touches stock. Only for a product, or a variant, the store
 * has chosen (`NotChosenInStore`).
 */
final readonly class MarkNotAvailableNowHandler
{
    public const string PERMISSION = CatalogPermissions::LISTING_UNAVAILABLE;

    public function __construct(
        private StoreListingChange $change,
        private ProductRepository $products,
        private StoreListingRepository $listings,
        private VariantRepository $variants,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|NotChosenInStore|ProductNotFound|Unauthorized|VariantNotFound
     */
    public function handle(MarkNotAvailableNow $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        $this->change->run(function () use ($command, $store): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $listing = $this->listings->of($store->value, $product->id());
            $variantId = null;

            if ($command->variantId !== null) {
                $variant = $this->variants->find($command->variantId);
                $variantId = $variant !== null && $variant->productId() === $product->id() ? $variant->id() : throw new VariantNotFound($command->variantId);
            }

            $listing->markUnavailable($variantId, true);
            $entry = ListAudit::changed('listing', 'unavailable_marked', $product->id(), $listing->pullChanges(), $listing->snapshot(), $store->value);

            if ($entry === null) {
                return [null, []];
            }

            $this->listings->save($listing);

            return [null, [$entry]];
        });
    }
}
