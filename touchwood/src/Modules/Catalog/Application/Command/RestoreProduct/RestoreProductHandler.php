<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RestoreProduct;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\InvalidStageChange;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\ProductNotReady;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Enums\ProductStage;
use Shared\Application\Unauthorized;

/**
 * **Restoring an archived product** (catalog.md §4.1): `catalog.product.archive`, as its shared data.
 * It comes back to the stage it left (amendment 3(m)): a draft abandoned comes back a draft, so making
 * it ready stays `catalog.product.publish`'s; a ready product comes back ready, Inactive in every
 * store — every readiness rule holding, as for making a draft ready (`ProductNotReady`).
 */
final readonly class RestoreProductHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_ARCHIVE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private Readiness $readiness,
        private ProductEvents $events,
    ) {}

    /**
     * @throws InvalidStageChange|ProductNotFound|ProductNotReady|Unauthorized
     */
    public function handle(RestoreProduct $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $product->restore();

            if ($product->pullChanges() === []) {
                return [null, []];
            }

            // Back to ready only whole; a draft abandoned comes back a draft (amendment 3(m)).
            if ($product->stage() === ProductStage::Ready) {
                $missing = $this->readiness->missing($product);

                if ($missing !== []) {
                    throw new ProductNotReady($missing);
                }
            }

            $this->products->update($product);
            $this->events->restored($product);

            return [null, [ListAudit::replaced('product', 'restored', $product->id(), 'stage', 'ARCHIVED', $product->stage()->value)]];
        });
    }
}
