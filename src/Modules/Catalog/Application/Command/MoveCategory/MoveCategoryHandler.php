<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MoveCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\CategoryInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryHoldsProducts;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryLoop;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\ValueObject\ListPosition;
use Shared\Application\Unauthorized;

/**
 * **Moving a category** (catalog.md §1.5): under another active category that holds no product,
 * never under itself or
 * anything below it (`CategoryLoop`), and placed among its new siblings in every store. Its slugs do
 * not change — an address names the category, not its path — so its addresses stay as they were.
 */
final readonly class MoveCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
        private CategoryInput $input,
    ) {}

    /**
     * @throws CategoryHoldsProducts|CategoryInactive|CategoryLoop|CategoryNotFound|InvalidCatalogAttribute|Unauthorized
     */
    public function handle(MoveCategory $command): void
    {
        $this->change->authorize(self::PERMISSION);
        ListPosition::check($command->rank, 'rank');

        $this->change->run(ListLocks::CATEGORIES, function () use ($command): array {
            $category = $this->categories->byId($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);
            $parentId = $command->parentId === null || trim($command->parentId) === '' ? null : strtolower(trim($command->parentId));

            // The loop is refused before the parent is read: a category under itself has no other
            // reason to be refused.
            $category->moveUnder($parentId, $this->categories->idsBelow($category->id()));
            $this->input->parent($parentId);
            $this->input->requireNoProducts($parentId);
            $changes = $category->pullChanges();

            // Under the same parent nothing moves, and each store's order stays its admins'.
            if ($changes === []) {
                return [null, []];
            }

            $this->categories->update($category);
            $this->input->placeEverywhere($category->id(), $command->rank);

            // Among its new siblings it had no place before.
            $entry = ListAudit::changed('category', 'moved', $category->id(), [...$changes, 'rank' => null], [...$category->snapshot(), 'rank' => $command->rank]);

            return [null, $entry === null ? [] : [$entry]];
        });
    }
}
