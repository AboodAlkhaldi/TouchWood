<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Media;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ReadyPhotos;
use Modules\Catalog\Domain\Exception\ProductNotReady;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MediaUseDto;

/**
 * Product and variant photos as Platform media (catalog.md §1.1, §1.2, amendment 3(b)). Deleting a
 * photo's file takes it out of the gallery or the variant's photos, audited — **except the last
 * ready photo of a `READY` product**, a use that blocks the delete: a product shown is never left
 * without a photo. Detaching changes the product's shared data, so it takes `catalog.product.update`
 * as editing it would, under the products' lock — and asks the blocking question again there, since a
 * product may have been made ready after Platform asked. Each product whose photos changed is
 * `ProductChanged`, once.
 */
final readonly class ProductPhotosUsage implements MediaUsage
{
    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
        private ReadyPhotos $readyPhotos,
        private ProductAccess $access,
        private ListLocks $locks,
        private PlatformApi $platform,
        private ProductEvents $events,
    ) {}

    public function usesOf(string $mediaId): array
    {
        $uses = [];

        foreach ($this->products->withPhoto($mediaId) as $productId) {
            $uses[] = new MediaUseDto('catalog.product', $productId, $this->blocks($productId, $mediaId));
        }

        foreach ($this->variants->withPhoto($mediaId) as $variantId) {
            $uses[] = new MediaUseDto('catalog.variant', $variantId, false);
        }

        return $uses;
    }

    public function detach(string $mediaId): void
    {
        $productIds = $this->products->withPhoto($mediaId);
        $variantIds = $this->variants->withPhoto($mediaId);

        if ($productIds === [] && $variantIds === []) {
            return;
        }

        $this->access->authorize(CatalogPermissions::PRODUCT_UPDATE);
        $this->locks->lock(ListLocks::PRODUCTS);
        $changed = [];

        foreach ($this->products->withPhoto($mediaId) as $productId) {
            if ($this->blocks($productId, $mediaId)) {
                throw new ProductNotReady(['photos']);
            }

            $this->products->removePhoto($productId, $mediaId);
            $this->platform->recordAudit(ListAudit::replaced('product', 'photo_detached', $productId, 'media_id', strtolower($mediaId), null));
            $changed[$productId] = true;
        }

        foreach ($this->variants->withPhoto($mediaId) as $variantId) {
            $this->variants->removePhoto($variantId, $mediaId);
            $this->platform->recordAudit(ListAudit::replaced('variant', 'photo_detached', $variantId, 'media_id', strtolower($mediaId), null));
            $productId = $this->variants->find($variantId)?->productId();

            if ($productId !== null) {
                $changed[$productId] = true;
            }
        }

        foreach (array_keys($changed) as $productId) {
            $product = $this->products->find((string) $productId);

            if ($product !== null) {
                $this->events->changed($product);
            }
        }
    }

    private function blocks(string $productId, string $mediaId): bool
    {
        return $this->products->find($productId)?->stage() === ProductStage::Ready && $this->readyPhotos->isLast($productId, $mediaId);
    }
}
