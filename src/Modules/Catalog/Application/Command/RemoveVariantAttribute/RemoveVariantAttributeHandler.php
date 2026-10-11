<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RemoveVariantAttribute;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Domain\Exception\DuplicateCombination;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\Combination;
use Shared\Application\Unauthorized;

/**
 * **A product's variants no longer made of an attribute** (catalog.md §1.7, P27; amendment 16(b)):
 * `catalog.product.update`. Each variant, archived ones too, gives up its value of it — **refused
 * while two variants would become alike** (`DuplicateCombination`), so the last attribute goes only
 * once one variant is left: "Has Variants: No". Audited once.
 */
final readonly class RemoveVariantAttributeHandler
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
     * @throws DuplicateCombination|InvalidCatalogAttribute|ProductNotFound|Unauthorized
     */
    public function handle(RemoveVariantAttribute $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $attributeId = strtolower(trim($command->attributeId));
            $held = $this->products->variantAttributes($product->id());

            if (! in_array($attributeId, $held, true)) {
                throw new InvalidCatalogAttribute('attribute_id', 'an attribute its variants are made of');
            }

            $variants = $this->variants->ofProduct($product->id());
            $combinations = [];

            foreach ($variants as $variant) {
                $valueIds = $variant->combination()->valueIds;
                unset($valueIds[$attributeId]);
                $combination = Combination::reconstitute($valueIds);

                if (isset($combinations[$combination->key()])) {
                    throw new DuplicateCombination;
                }

                $combinations[$combination->key()] = $combination;
            }

            foreach ($variants as $variant) {
                $valueIds = $variant->combination()->valueIds;
                unset($valueIds[$attributeId]);
                $variant->edit(Combination::reconstitute($valueIds), $variant->details(), $variant->measures(), $variant->position());
                $variant->pullChanges();
                $this->variants->update($variant);
            }

            $now = array_values(array_diff($held, [$attributeId]));
            $this->products->replaceVariantAttributes($product->id(), $now);
            $this->events->changed($product);
            $this->listingRows->refresh([$product->id()]);

            return [null, array_filter([ListAudit::changed('product', 'variant_attribute_removed', $product->id(), ['variant_attributes' => implode(',', $held)], ['variant_attributes' => implode(',', $now)])])];
        });
    }
}
