<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteDraftProduct;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ProductDeletion;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\InvalidStageChange;
use Modules\Catalog\Domain\Exception\ProductArchived;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Deleting a draft** (catalog.md §4.1, §9.5 #2, amendment 3(h)): under `catalog.product.archive`,
 * only a product never shown or sold. It goes whole — variants, slugs, codes — and its slugs and
 * codes are free again; a draft also lets go of a code no variant of it carries any more (amendment
 * 3(c)). A product that was ever ready is archived instead; an archived one is restored first.
 */
final readonly class DeleteDraftProductHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_ARCHIVE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private ProductDeletion $deletion,
        private Readiness $readiness,
    ) {}

    /**
     * @throws InvalidStageChange|ProductArchived|ProductNotFound|Unauthorized
     */
    public function handle(DeleteDraftProduct $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->readiness->requireNotArchived($product);

            if (! $product->isDraft()) {
                throw new InvalidStageChange;
            }

            return [null, $this->deletion->delete($product)];
        });
    }
}
