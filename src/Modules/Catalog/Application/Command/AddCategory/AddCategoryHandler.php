<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\CatalogImages;
use Modules\Catalog\Application\Lists\CategoryAudiences;
use Modules\Catalog\Application\Lists\CategoryInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryHoldsProducts;
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
 * is every store's. Its parent must be active, and **hold no product**: products sit at the end of
 * the tree (`CategoryHoldsProducts`). **It goes last among its siblings in every store**, read under
 * the categories' lock; each store's admins drag it into place there (amendment 16(d)). **Under a
 * parent it takes the parent's switches — who sees it — in every store** (§1.13, P24); at the top it
 * has none, and both kinds of account see it (14(b)).
 */
final readonly class AddCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
        private CategoryInput $input,
        private CatalogImages $images,
        private CategoryAudiences $audiences,
    ) {}

    /**
     * @return string the new category's id
     *
     * @throws CategoryHoldsProducts|CategoryInactive|CategoryNotFound|InvalidCatalogAttribute|SlugTaken|Unauthorized
     */
    public function handle(AddCategory $command): string
    {
        $this->change->authorize(self::PERMISSION);
        [$name, $slugs] = $this->input->names($command->nameAr, $command->nameEn, $command->slugAr, $command->slugEn);
        $id = $this->categories->nextId();

        return $this->change->run(ListLocks::CATEGORIES, function () use ($id, $name, $slugs, $command): array {
            // Inside, so a retried attempt asks again: the file may have been deleted meanwhile.
            $image = $this->images->check('image_media_id', $command->imageMediaId);
            $parentId = $this->input->parent($command->parentId);
            $this->input->requireNoProducts($parentId);
            $this->input->requireFreeSlugs($slugs);

            $category = Category::add($id, $parentId, $name, $slugs, $image);
            $this->categories->add($category);
            $placed = $this->input->placeLast($id, $parentId);
            // Under a parent, it takes the parent's switches in every store (P24).
            $audiences = $parentId === null ? [] : $this->audiences->takeParents($parentId, [$id]);

            return [$id, [ListAudit::added('category', $id, $category->snapshot()), ...$placed, ...$audiences]];
        });
    }
}
