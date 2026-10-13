<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderVariantAttributes;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Ordering a product's variant attributes** (catalog.md §1.7, P27, P29): `catalog.product.update`.
 * Saved as the ids in their new order: those sent first, the rest after in the order they had, so
 * two people ordering at once lose nothing; positions written again 1, 2, 3 …. A variant's values are
 * kept in their attributes' id order, so no variant is rewritten. Audited when the order changed.
 */
final readonly class OrderVariantAttributesHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private ProductEvents $events,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ProductNotFound|Unauthorized
     */
    public function handle(OrderVariantAttributes $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $held = $this->products->variantAttributes($product->id());
            $sent = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $command->attributeIds)));

            if (array_diff($sent, $held) !== []) {
                throw new InvalidCatalogAttribute('attribute_ids', 'attributes its variants are made of');
            }

            $now = [...$sent, ...array_values(array_diff($held, $sent))];

            if ($now === $held) {
                return [null, []];
            }

            $this->products->replaceVariantAttributes($product->id(), $now);
            $this->events->changed($product);

            return [null, array_filter([ListAudit::changed('product', 'variant_attributes_ordered', $product->id(), ['variant_attributes' => implode(',', $held)], ['variant_attributes' => implode(',', $now)])])];
        });
    }
}
