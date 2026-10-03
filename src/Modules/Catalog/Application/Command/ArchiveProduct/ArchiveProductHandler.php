<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ArchiveProduct;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Archiving a product** (catalog.md §4.1, §9.3 #19): `catalog.product.archive`, as its shared data —
 * a ready product retired, or a draft abandoned. It becomes Inactive in every store (their rows,
 * step 4); its codes stay with it; restoring brings it back.
 */
final readonly class ArchiveProductHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_ARCHIVE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private ProductEvents $events,
    ) {}

    /**
     * @throws ProductNotFound|Unauthorized
     */
    public function handle(ArchiveProduct $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $was = $product->stage()->value;
            $product->archive();

            if ($product->pullChanges() === []) {
                return [null, []];
            }

            $this->products->update($product);
            $this->events->archived($product->id());

            return [null, [ListAudit::replaced('product', 'archived', $product->id(), 'stage', $was, 'ARCHIVED')]];
        });
    }
}
