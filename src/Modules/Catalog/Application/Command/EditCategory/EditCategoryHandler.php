<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Lists\CatalogImages;
use Modules\Catalog\Application\Lists\CategoryInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Editing a category's names, slugs and photo** (catalog.md §1.5). A slug that changes leaves the
 * old one held, redirecting (§1.1). **Its name is searched** for everything in it and below it
 * (amendment 5(c), (g)), so a new name writes those products' listing rows again — under the
 * products' lock, taken first, as every change to them.
 */
final readonly class EditCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
        private CategoryInput $input,
        private CatalogImages $images,
        private ProductRepository $products,
        private ListingRows $listingRows,
    ) {}

    /**
     * @throws CategoryNotFound|InvalidCatalogAttribute|SlugTaken|Unauthorized
     */
    public function handle(EditCategory $command): void
    {
        $this->change->authorize(self::PERMISSION);
        [$name, $slugs] = $this->input->names($command->nameAr, $command->nameEn, $command->slugAr, $command->slugEn);

        $this->change->runAfterProducts(ListLocks::CATEGORIES, function () use ($name, $slugs, $command): array {
            // Inside, so a retried attempt asks again: the file may have been deleted meanwhile.
            $image = $this->images->check('image_media_id', $command->imageMediaId);
            $category = $this->categories->byId($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);
            $this->input->requireFreeSlugs($slugs, $category->id());

            $before = $category->snapshot();
            $category->edit($name, $slugs, $image);
            $entry = ListAudit::changed('category', 'edited', $category->id(), $category->pullChanges(), $category->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->categories->update($category);

            if ($before['name_ar'] !== $name->ar || $before['name_en'] !== $name->en) {
                $this->listingRows->refresh($this->products->idsInCategories([$category->id(), ...$this->categories->idsBelow($category->id())]));
            }

            return [null, [$entry]];
        });
    }
}
