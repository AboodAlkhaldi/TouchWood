<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderVariants;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Model\Variant;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;

/**
 * **Ordering a product's variants** (catalog.md §1.2, P29, amendment 16(d)): `catalog.product.update`,
 * as the product's shared data. Saved as the ids in their new order: those sent first, the rest after
 * in the order they had, archived ones included, so two people ordering at once lose nothing; their
 * places written again 1, 2, 3 …. The listing follows, since a card shows the first variant in the
 * product's order (§4.5). Audited when the order changed.
 */
final readonly class OrderVariantsHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
        private ProductEvents $events,
        private ListingRows $listingRows,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ProductNotFound|Unauthorized
     */
    public function handle(OrderVariants $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $held = array_map(static fn (Variant $variant): string => $variant->id(), $this->variants->ofProduct($product->id()));
            $sent = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $command->variantIds)));

            if (array_diff($sent, $held) !== []) {
                throw new InvalidCatalogAttribute('variant_ids', "the product's own variants");
            }

            $now = [...$sent, ...array_values(array_diff($held, $sent))];

            if ($now === $held) {
                return [null, []];
            }

            $this->variants->renumber($product->id(), $now);
            $this->events->changed($product);
            $this->listingRows->refresh([$product->id()]);

            return [null, array_filter([ListAudit::changed('product', 'variants_ordered', $product->id(), ['variants' => implode(',', $held)], ['variants' => implode(',', $now)])])];
        });
    }
}
