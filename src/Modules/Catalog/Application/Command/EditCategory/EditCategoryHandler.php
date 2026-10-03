<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\CatalogImages;
use Modules\Catalog\Application\Lists\CategoryInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Editing a category's names, slugs and photo** (catalog.md §1.5). A slug that changes leaves the
 * old one held, redirecting (§1.1).
 */
final readonly class EditCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
        private CategoryInput $input,
        private CatalogImages $images,
    ) {}

    /**
     * @throws CategoryNotFound|InvalidCatalogAttribute|SlugTaken|Unauthorized
     */
    public function handle(EditCategory $command): void
    {
        $this->change->authorize(self::PERMISSION);
        [$name, $slugs] = $this->input->names($command->nameAr, $command->nameEn, $command->slugAr, $command->slugEn);

        $this->change->run(ListLocks::CATEGORIES, function () use ($name, $slugs, $command): array {
            // Inside, so a retried attempt asks again: the file may have been deleted meanwhile.
            $image = $this->images->check('image_media_id', $command->imageMediaId);
            $category = $this->categories->byId($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);
            $this->input->requireFreeSlugs($slugs, $category->id());

            $category->edit($name, $slugs, $image);
            $entry = ListAudit::changed('category', 'edited', $category->id(), $category->pullChanges(), $category->snapshot());

            if ($entry === null) {
                return [null, []];
            }

            $this->categories->update($category);

            return [null, [$entry]];
        });
    }
}
