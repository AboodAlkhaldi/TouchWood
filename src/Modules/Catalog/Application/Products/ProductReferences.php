<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\CategoryNotLowest;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\Attribute;
use Modules\Catalog\Domain\Model\AttributeSet;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;

/**
 * The list rows a product points at, read inside the product's change **with their rows locked**
 * (catalog.md §1.1). A list's own change locks the same row before it asks whether a product uses
 * it, so the two meet on the row: a brand deleted while a product takes it is either seen gone here,
 * or sees the product there — never a product pointing at nothing. A list's change never takes the
 * products' lock, so the two never wait on each other in a circle.
 *
 * What a product **newly** points at must be active (and a category the lowest of its branch); what
 * it already points at may stay after it was deactivated — each product's fate there is chosen when
 * its category or brand is deactivated (step 4).
 */
final readonly class ProductReferences
{
    public function __construct(
        private BrandRepository $brands,
        private CategoryRepository $categories,
        private WarrantyRepository $warranties,
        private AttributeRepository $attributes,
    ) {}

    /**
     * @return string the brand's id
     *
     * @throws BrandInactive|BrandNotFound
     */
    public function brand(string $brandId, ?string $held = null): string
    {
        $brand = $this->brands->byId($brandId) ?? throw new BrandNotFound($brandId);

        if (! $brand->isActive() && $brand->id() !== $held) {
            throw new BrandInactive;
        }

        return $brand->id();
    }

    /**
     * The default brand, which is always active (§1.6): the one a new product starts on.
     *
     * @throws BrandNotFound
     */
    public function defaultBrand(): string
    {
        return $this->brands->defaultBrand()?->id() ?? throw new BrandNotFound;
    }

    /**
     * @throws CategoryInactive|CategoryNotFound|CategoryNotLowest
     */
    public function category(?string $categoryId, ?string $held = null): ?string
    {
        if ($categoryId === null || trim($categoryId) === '') {
            return null;
        }

        $category = $this->categories->byId($categoryId) ?? throw new CategoryNotFound($categoryId);

        if ($category->id() === $held) {
            return $held;
        }

        if (! $category->isActive()) {
            throw new CategoryInactive;
        }

        if ($this->categories->childrenOf($category->id()) !== []) {
            throw new CategoryNotLowest;
        }

        return $category->id();
    }

    /**
     * @throws ListItemInactive|ListItemNotFound
     */
    public function warranty(?string $warrantyId, ?string $held = null): ?string
    {
        if ($warrantyId === null || trim($warrantyId) === '') {
            return null;
        }

        $warranty = $this->warranties->byId($warrantyId) ?? throw new ListItemNotFound($warrantyId);

        if (! $warranty->isActive() && $warranty->id() !== $held) {
            throw new ListItemInactive;
        }

        return $warranty->id();
    }

    /**
     * @throws ListItemInactive|ListItemNotFound
     */
    public function attributeSet(?string $setId, ?string $held = null): ?AttributeSet
    {
        if ($setId === null || trim($setId) === '') {
            return null;
        }

        $set = $this->attributes->setById($setId) ?? throw new ListItemNotFound($setId);

        if (! $set->isActive() && $set->id() !== $held) {
            throw new ListItemInactive;
        }

        return $set;
    }

    /**
     * The set's attributes, in its order, their rows locked: none of them can be deleted while the
     * set holds it, and none changes job while the set holds it (step 2), so a variant's values stay
     * values of variant-making attributes.
     *
     * @return list<Attribute>
     *
     * @throws ListItemNotFound
     */
    public function members(AttributeSet $set): array
    {
        return array_map(
            fn (string $id): Attribute => $this->attributes->byId($id) ?? throw new ListItemNotFound($id),
            $set->memberIds(),
        );
    }
}
