<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetProductGallery;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ProductParts;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\ProductNotReady;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **A product's gallery** (catalog.md §1.1, §9.3 #6): `catalog.product.update`, as its shared data —
 * at most 20 public images, each once, in the order given. The images are checked inside the change,
 * so a retried one asks again.
 */
final readonly class SetProductGalleryHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public const int MAX = 20;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private ProductParts $parts,
        private Readiness $readiness,
        private ProductEvents $events,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ProductNotFound|ProductNotReady|TooMany|Unauthorized
     */
    public function handle(SetProductGallery $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $before = $this->products->gallery($product->id());
            $after = $this->parts->photos('photos', $command->mediaIds, self::MAX);

            if ($after === $before) {
                return [null, []];
            }

            // A ready product keeps a photo whose sizes are ready (§1.1).
            $this->readiness->requireKept($product, gallery: $after);
            $this->products->replaceGallery($product->id(), $after);
            $this->events->changed($product);

            return [null, [ListAudit::replaced('product', 'gallery_changed', $product->id(), 'media_ids', implode(',', $before) ?: null, implode(',', $after) ?: null)]];
        });
    }
}
