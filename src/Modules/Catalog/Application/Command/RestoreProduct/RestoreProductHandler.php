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
use Shared\Application\Unauthorized;

/**
 * **Restoring an archived product** (catalog.md §4.1): `catalog.product.archive`, as its shared data.
 * It comes back ready, Inactive in every store — so every readiness rule must hold, as for making a
 * draft ready (`ProductNotReady`): a draft archived when abandoned comes back only once it is whole.
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

            $missing = $this->readiness->missing($product);

            if ($missing !== []) {
                throw new ProductNotReady($missing);
            }

            $this->products->update($product);
            $this->events->restored($product->id());

            return [null, [ListAudit::replaced('product', 'restored', $product->id(), 'stage', 'ARCHIVED', 'READY')]];
        });
    }
}
