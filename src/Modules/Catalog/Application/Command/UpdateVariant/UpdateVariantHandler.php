<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UpdateVariant;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\VariantInput;
use Modules\Catalog\Domain\Exception\CodeTaken;
use Modules\Catalog\Domain\Exception\DuplicateCombination;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\InvalidStageChange;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Domain\ValueObject\VariantMeasures;
use Shared\Application\Unauthorized;

/**
 * **Editing a variant** (catalog.md §1.2): `catalog.product.update`, as the product's shared data.
 * **Its values stay editable**, a ready product's too (amendment 3(j)), its combination still its
 * own among the product's variants; a value or detail it already has may stay after it was
 * deactivated. **Its code changes here only in a draft** (amendment 3(c)), and a code no variant of
 * the draft carries any more is let go, free again; once the product is ready, a code is corrected
 * with its own permission (`CorrectVariantCode`).
 */
final readonly class UpdateVariantHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
        private VariantInput $input,
    ) {}

    /**
     * @throws CodeTaken|DuplicateCombination|InvalidCatalogAttribute|InvalidStageChange|ListItemInactive|ListItemNotFound|Unauthorized|VariantNotFound
     */
    public function handle(UpdateVariant $command): void
    {
        $this->access->authorize(self::PERMISSION);
        $code = ProductCode::of($command->code);
        $measures = VariantMeasures::of($command->weightGrams, $command->lengthMm, $command->widthMm, $command->heightMm);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command, $code, $measures): array {
            $variant = $this->variants->byId($command->variantId) ?? throw new VariantNotFound($command->variantId);
            // A variant never outlives its product (the key cascades), so this always finds it.
            $product = $this->products->byId($variant->productId()) ?? throw new LogicException('A variant without its product.');
            $oldCode = $variant->code();

            if (! $code->equals($oldCode)) {
                if (! $product->isDraft()) {
                    throw new InvalidStageChange;
                }

                $this->input->freeCode($product, $code);
                $variant->changeCode($code);
            }

            $combination = $this->input->combination($product, $command->values, array_values($variant->combination()->valueIds));

            if ($this->variants->combinationTaken($product->id(), $combination->key(), $variant->id())) {
                throw new DuplicateCombination;
            }

            $variant->edit($combination, $this->input->details($command->details, array_keys($variant->details())), $measures, $command->position);
            $entry = ListAudit::changed('variant', 'edited', $variant->id(), $variant->pullChanges(), $variant->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->products->holdCode($product->id(), $code->value);
            $this->variants->update($variant);

            if (! $code->equals($oldCode) && ! $this->variants->codeInUse($product->id(), $oldCode->value)) {
                $this->products->releaseCode($product->id(), $oldCode->value);
            }

            return [null, [$entry]];
        });
    }
}
