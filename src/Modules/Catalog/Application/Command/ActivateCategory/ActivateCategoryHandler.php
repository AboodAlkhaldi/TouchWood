<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\CategoryInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Model\Category;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **Activating a category again** (catalog.md §1.5) brings back exactly what went with it: each
 * category below that was deactivated because its parent went, down to one deactivated on its own,
 * which stays so — and so does everything under that one. Under a deactivated parent it is refused:
 * activate the parent first.
 */
final readonly class ActivateCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
        private CategoryInput $input,
    ) {}

    /**
     * @throws CategoryInactive|CategoryNotFound|Unauthorized
     */
    public function handle(ActivateCategory $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::CATEGORIES, function () use ($command): array {
            $category = $this->categories->byId($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);

            if ($category->isActive()) {
                return [null, []];
            }

            $this->input->parent($category->parentId());

            $entries = [];
            $this->activate($category, $entries);

            return [null, $entries];
        });
    }

    /**
     * @param  list<AuditEntryDto>  $entries
     */
    private function activate(Category $category, array &$entries): void
    {
        $category->activate();
        $entry = ListAudit::changed('category', 'activated', $category->id(), $category->pullChanges(), $category->snapshot());

        if ($entry !== null) {
            $this->categories->update($category);
            $entries[] = $entry;
        }

        foreach ($this->categories->childrenOf($category->id()) as $child) {
            if (! $child->isActive() && $child->deactivatedWithParent()) {
                $this->activate($child, $entries);
            }
        }
    }
}
