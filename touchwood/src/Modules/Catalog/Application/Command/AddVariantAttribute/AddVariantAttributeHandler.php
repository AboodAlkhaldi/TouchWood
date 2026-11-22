<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddVariantAttribute;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ProductReferences;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\Combination;
use Shared\Application\Unauthorized;

/**
 * **A product's variants made of one more attribute** (catalog.md §1.7, P27; owner, 2026-10-09 and
 * 2026-10-10, amendment 16(b)): `catalog.product.update`, as the product's shared data. Any of the
 * library's variant-making attributes, active, not one it has already — at most ten — and **every
 * variant, archived ones too, given its value of it**, all or nothing: a value of that attribute,
 * active. The first one is "Has Variants: Yes" (its one variant given its value). The attribute goes
 * last in the product's order; no two variants can become alike by gaining a value. Audited once.
 */
final readonly class AddVariantAttributeHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public const int MAX_ATTRIBUTES = 10;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
        private ProductReferences $references,
        private AttributeRepository $attributes,
        private ProductEvents $events,
        private ListingRows $listingRows,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|ProductNotFound|Unauthorized
     */
    public function handle(AddVariantAttribute $command): void
    {
        $this->access->authorize(self::PERMISSION);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $attribute = $this->references->newVariantAttribute($command->attributeId);
            $held = $this->products->variantAttributes($product->id());

            if (in_array($attribute->id(), $held, true)) {
                throw new InvalidCatalogAttribute('attribute_id', 'an attribute its variants are not made of yet');
            }

            if (count($held) >= self::MAX_ATTRIBUTES) {
                throw new InvalidCatalogAttribute('attribute_id', 'at most '.self::MAX_ATTRIBUTES.' attributes');
            }

            $given = [];

            foreach ($command->values as $variantId => $valueId) {
                $given[strtolower(trim((string) $variantId))] = is_string($valueId) ? $valueId : '';
            }

            $variants = $this->variants->ofProduct($product->id());

            if (count($given) !== count($variants)) {
                throw new InvalidCatalogAttribute('values', 'a value for each of its variants, archived ones too');
            }

            foreach ($variants as $variant) {
                $valueId = $given[$variant->id()] ?? throw new InvalidCatalogAttribute('values', 'a value for each of its variants, archived ones too');
                $value = $this->attributes->valueById($valueId) ?? throw new ListItemNotFound($valueId);

                if ($value->attributeId() !== $attribute->id()) {
                    throw new InvalidCatalogAttribute('values', "values of {$attribute->name()->en}");
                }

                if (! $value->isActive()) {
                    throw new ListItemInactive;
                }

                $valueIds = [...$variant->combination()->valueIds, $attribute->id() => $value->id()];
                ksort($valueIds, SORT_STRING);
                $variant->edit(Combination::reconstitute($valueIds), $variant->details(), $variant->measures(), $variant->position());
                $variant->pullChanges();
                $this->variants->update($variant);
            }

            $now = [...$held, $attribute->id()];
            $this->products->replaceVariantAttributes($product->id(), $now);
            $this->events->changed($product);
            $this->listingRows->refresh([$product->id()]);

            return [null, array_filter([ListAudit::changed('product', 'variant_attribute_added', $product->id(), ['variant_attributes' => implode(',', $held)], ['variant_attributes' => implode(',', $now)])])];
        });
    }
}
