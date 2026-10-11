<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddValueFromProduct;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ProductReferences;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\NameTaken;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Model\AttributeValue;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Shared\Application\Unauthorized;

/**
 * **A value made from a product's Variants tab** (catalog.md §1.7, P28; owner, 2026-10-09, amendment
 * 16(c): "we can create new one from this tab"): **whoever may change the product** —
 * `catalog.product.update` where it is on, as `UpdateProduct` asks — may add a value to any active
 * variant-making attribute, as the Attributes screen does: both names, a colour's swatch, never named
 * as another value of it in either language (`NameTaken`); active, **last in its attribute's order**.
 * Under the products' lock and then the attributes', so the product's stores are asked as they are
 * (`ProductAccess`); audited as one added on the Attributes screen.
 */
final readonly class AddValueFromProductHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private ProductReferences $references,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @return string the new value's id
     *
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|NameTaken|ProductNotFound|Unauthorized
     */
    public function handle(AddValueFromProduct $command): string
    {
        $this->access->authorize(self::PERMISSION);
        $name = LocalizedName::of($command->nameAr, $command->nameEn, AttributeValue::NAME_MAX);
        $id = $this->attributes->nextId();

        return $this->change->runAfterProducts(ListLocks::ATTRIBUTES, function () use ($command, $name, $id): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $attribute = $this->references->newVariantAttribute($command->attributeId);
            $last = array_reduce($this->attributes->valuesOf($attribute->id()), static fn (int $max, AttributeValue $value): int => max($max, $value->position()), 0);
            $value = AttributeValue::add($id, $attribute, $name, $command->swatch, $last + 1);

            if ($this->attributes->valueNameTaken($attribute->id(), $name)) {
                throw new NameTaken('value');
            }

            $this->attributes->addValue($value);

            return [$id, [ListAudit::added('attribute_value', $id, ['attribute_id' => $attribute->id(), ...$value->snapshot()])]];
        });
    }
}
