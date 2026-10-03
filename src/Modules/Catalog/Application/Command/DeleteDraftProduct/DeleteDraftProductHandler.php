<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteDraftProduct;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Domain\Exception\InvalidStageChange;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;

/**
 * **Deleting a draft** (catalog.md §4.1, §9.5 #2, amendment 3(h)): under `catalog.product.archive`,
 * only a product never shown or sold. It goes whole — variants, slugs, codes — and its slugs and
 * codes are free again: the one way a code ever leaves a product. A product that was ever ready is
 * archived instead.
 */
final readonly class DeleteDraftProductHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_ARCHIVE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
    ) {}

    /**
     * @throws InvalidStageChange|ProductNotFound|Unauthorized
     */
    public function handle(DeleteDraftProduct $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);

            if (! $product->isDraft()) {
                throw new InvalidStageChange;
            }

            $entries = [];

            foreach ($this->variants->ofProduct($product->id()) as $variant) {
                $entries[] = ListAudit::deleted('variant', $variant->id(), ['product_id' => $product->id(), ...$variant->snapshot()]);
            }

            $entries[] = ListAudit::deleted('product', $product->id(), [...$product->snapshot(), 'codes' => implode(',', $this->products->codesOf($product->id())) ?: null]);
            $this->products->delete($product->id());

            return [null, $entries];
        });
    }
}
