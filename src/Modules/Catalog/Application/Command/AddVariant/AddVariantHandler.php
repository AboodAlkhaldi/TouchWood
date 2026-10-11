<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddVariant;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\VariantInput;
use Modules\Catalog\Domain\Exception\CodeTaken;
use Modules\Catalog\Domain\Exception\DuplicateCombination;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Model\Variant;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Domain\ValueObject\VariantMeasures;
use Shared\Application\Unauthorized;

/**
 * **Adding a variant** (catalog.md §1.2, §3): `catalog.product.update`, as the product's shared data.
 * Its code is one no other variant carries (amendment 16(a)), and one the product holds or no other
 * product holds or held (`CodeTaken`, amendment 3(e)) — the product then holds it; its values are one active value of each of the
 * product's variant attributes (amendment 16(b)), a combination no other variant of the product has, archived ones included
 * (`DuplicateCombination`). A variant added later is chosen in no store (§1.3). Under the products'
 * lock.
 */
final readonly class AddVariantHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
        private VariantInput $input,
        private ProductEvents $events,
    ) {}

    /**
     * @return string the new variant's id
     *
     * @throws CodeTaken|DuplicateCombination|InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|ProductNotFound|Unauthorized
     */
    public function handle(AddVariant $command): string
    {
        $this->access->authorize(self::PERMISSION);
        $code = ProductCode::of($command->code);
        $measures = VariantMeasures::of($command->weightGrams, $command->lengthMm, $command->widthMm, $command->heightMm);
        $id = $this->variants->nextId();

        return $this->change->run(ListLocks::PRODUCTS, function () use ($command, $code, $measures, $id): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $this->input->freeCode($product, $code);
            $combination = $this->input->combination($product, $command->values);

            if ($this->variants->combinationTaken($product->id(), $combination->key())) {
                throw new DuplicateCombination;
            }

            $variant = Variant::add($id, $product->id(), $code, $combination, $this->input->details($command->details), $measures, $command->position);
            $this->products->holdCode($product->id(), $code->value);
            $this->variants->add($variant);
            $this->events->variantAdded($product, $id);

            return [$id, [ListAudit::added('variant', $id, ['product_id' => $product->id(), ...$variant->snapshot()])]];
        });
    }
}
