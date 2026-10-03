<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\CatalogImages;
use Modules\Catalog\Application\Lists\CategoryInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Model\Category;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Adding a category** (catalog.md §1.5), under `catalog.category.manage` with All stores: the tree
 * is every store's. Its parent must be active. "A category that holds products takes no
 * sub-category" joins the refusals with the products (step 3); until then no category holds one.
 */
final readonly class AddCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
        private CategoryInput $input,
        private CatalogImages $images,
    ) {}

    /**
     * @return string the new category's id
     *
     * @throws CategoryInactive|CategoryNotFound|InvalidCatalogAttribute|SlugTaken|Unauthorized
     */
    public function handle(AddCategory $command): string
    {
        $this->change->authorize(self::PERMISSION);
        [$name, $slugs] = $this->input->names($command->nameAr, $command->nameEn, $command->slugAr, $command->slugEn);
        $image = $this->images->check('image_media_id', $command->imageMediaId);
        $id = $this->categories->nextId();

        return $this->change->run(ListLocks::CATEGORIES, function () use ($id, $name, $slugs, $image, $command): array {
            $parentId = $this->input->parent($command->parentId);
            $this->input->requireFreeSlugs($slugs);

            $category = Category::add($id, $parentId, $name, $slugs, $image);
            $this->categories->add($category);
            $this->input->placeEverywhere($id, $command->rank);

            return [$id, [ListAudit::added('category', $id, [...$category->snapshot(), 'rank' => $command->rank])]];
        });
    }
}
