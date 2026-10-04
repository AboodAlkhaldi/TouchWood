<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MarkProductReady;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\ProductArchived;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\ProductNotReady;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Making a product ready** (catalog.md §1.1, §4.1): `catalog.product.publish`, as its shared data.
 * Out of its draft only with every readiness rule met — each one missing named (`ProductNotReady`).
 * Stores may then choose it (step 4); it is shown nowhere until one does.
 */
final readonly class MarkProductReadyHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_PUBLISH;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private Readiness $readiness,
        private ProductEvents $events,
    ) {}

    /**
     * @throws ProductArchived|ProductNotFound|ProductNotReady|Unauthorized
     */
    public function handle(MarkProductReady $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $product->markReady();
            $changes = $product->pullChanges();

            if ($changes === []) {
                return [null, []];
            }

            $missing = $this->readiness->missing($product);

            if ($missing !== []) {
                throw new ProductNotReady($missing);
            }

            $this->products->update($product);
            $this->events->madeReady($product);

            return [null, [ListAudit::replaced('product', 'made_ready', $product->id(), 'stage', 'DRAFT', 'READY')]];
        });
    }
}
