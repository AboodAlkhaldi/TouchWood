<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateBrand;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Activating a brand again** (catalog.md §1.6): offered on the form, the filter and its page again,
 * and **the products hidden with it are shown again**. It changes products, so it takes the products'
 * lock before the brands'.
 */
final readonly class ActivateBrandHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private BrandRepository $brands,
        private ProductRepository $products,
        private ProductEvents $events,
        private ListingRows $listingRows,
    ) {}

    /**
     * @throws BrandNotFound|Unauthorized
     */
    public function handle(ActivateBrand $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->runAfterProducts(ListLocks::BRANDS, function () use ($command): array {
            $brand = $this->brands->byId($command->brandId) ?? throw new BrandNotFound($command->brandId);
            $brand->activate();
            $entry = ListAudit::changed('brand', 'activated', $brand->id(), $brand->pullChanges(), $brand->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->brands->update($brand);
            $entries = [$entry];

            foreach ($this->products->idsHiddenByBrand($brand->id()) as $productId) {
                $product = $this->products->byId($productId);

                if ($product === null) {
                    continue;
                }

                $product->hideWithBrand(false);
                $shown = ListAudit::changed('product', 'shown', $product->id(), $product->pullChanges(), $product->snapshot());

                if ($shown !== null) {
                    $this->products->update($product);
                    $this->events->changed($product);
                    $entries[] = $shown;
                }
            }

            $this->listingRows->refresh($this->products->idsWithBrand($brand->id()));

            return [null, $entries];
        });
    }
}
