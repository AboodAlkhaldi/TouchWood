<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditProductDetails;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ProductInput;
use Modules\Catalog\Application\Products\ProductReferences;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\AttributeSetLocked;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\CategoryNotLowest;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\ProductNotReady;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Shared\Application\Unauthorized;

/**
 * **Editing a product's own details** (catalog.md §1.1): `catalog.product.update`, as the product's
 * shared data. What it newly points at must be active — a category also the lowest of its branch;
 * what it already points at may stay. Its attribute set is fixed once it has a variant (§1.7). A
 * slug that changes leaves the old one held, redirecting.
 */
final readonly class EditProductDetailsHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
        private ProductInput $input,
        private ProductReferences $references,
        private Readiness $readiness,
        private ProductEvents $events,
        private ListingRows $listingRows,
    ) {}

    /**
     * @throws AttributeSetLocked|BrandInactive|BrandNotFound|CategoryInactive|CategoryNotFound|CategoryNotLowest|InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|ProductNotFound|ProductNotReady|SlugTaken|Unauthorized
     */
    public function handle(EditProductDetails $command): void
    {
        $this->access->authorize(self::PERMISSION);
        [$name, $slugs] = ProductInput::names($command->nameAr, $command->nameEn, $command->slugAr, $command->slugEn);
        $descriptionAr = ProductInput::description('description_ar', $command->descriptionAr);
        $descriptionEn = ProductInput::description('description_en', $command->descriptionEn);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command, $name, $slugs, $descriptionAr, $descriptionEn): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $this->input->requireFreeSlugs($slugs, $product->id());

            $product->editDetails(
                $name,
                $slugs,
                $descriptionAr,
                $descriptionEn,
                $this->references->brand($command->brandId, $product->brandId()),
                $this->references->category($command->categoryId, $product->categoryId()),
                $this->references->warranty($command->warrantyId, $product->warrantyId()),
                $this->references->attributeSet($command->attributeSetId, $product->attributeSetId())?->id(),
                $this->variants->hasAny($product->id()),
            );
            $entry = ListAudit::changed('product', 'edited', $product->id(), $product->pullChanges(), $product->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->readiness->requireKept($product);
            $this->products->update($product);
            $this->events->changed($product);
            $this->listingRows->refresh([$product->id()]);

            return [null, [$entry]];
        });
    }
}
