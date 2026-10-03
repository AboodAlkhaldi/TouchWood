<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **Deactivating a category** (catalog.md §1.5): every active category below it goes with it, each
 * remembering it went with its parent, so activating it again brings back only what was active
 * before. One step, all of it or none of it, and each category's change audited. Choosing each of
 * its products' fate — hide, leave or move — arrives with the products (step 4).
 */
final readonly class DeactivateCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
    ) {}

    /**
     * @throws CategoryNotFound|Unauthorized
     */
    public function handle(DeactivateCategory $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->run(ListLocks::CATEGORIES, function () use ($command): array {
            $category = $this->categories->byId($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);

            // Already deactivated: so is everything below it, and nothing changes.
            if (! $category->isActive()) {
                return [null, []];
            }

            $entries = [$this->deactivate($category->id(), false)];

            foreach ($this->categories->idsBelow($category->id()) as $id) {
                $entries[] = $this->deactivate($id, true);
            }

            return [null, array_values(array_filter($entries))];
        });
    }

    private function deactivate(string $id, bool $withParent): ?AuditEntryDto
    {
        $category = $this->categories->byId($id);

        if ($category === null) {
            return null;
        }

        $category->deactivate($withParent);
        $entry = ListAudit::changed('category', 'deactivated', $category->id(), $category->pullChanges(), $category->snapshot());

        if ($entry !== null) {
            $this->categories->update($category);
        }

        return $entry;
    }
}
