<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryNotEmpty;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Shared\Application\Unauthorized;

/**
 * **Deleting a category** (catalog.md §1.5): only one with no sub-category; otherwise refused until
 * they are moved. Its slugs and each store's place for it go with it. "Holds a product, in any
 * stage" joins the refusal with the products (step 3); until then no category holds one.
 */
final readonly class DeleteCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
    ) {}

    /**
     * @throws CategoryNotEmpty|CategoryNotFound|Unauthorized
     */
    public function handle(DeleteCategory $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::CATEGORIES, function () use ($command): array {
            $category = $this->categories->byId($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);

            if ($this->categories->childrenOf($category->id()) !== []) {
                throw new CategoryNotEmpty;
            }

            $was = $category->snapshot();
            $this->categories->delete($category->id());

            return [null, [ListAudit::deleted('category', $category->id(), $was)]];
        });
    }
}
