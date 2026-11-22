<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\MoveCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Lists\CategoryAudiences;
use Modules\Catalog\Application\Lists\CategoryInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryHoldsProducts;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryLoop;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;

/**
 * **Moving a category** (catalog.md §1.5): under another active category that holds no product,
 * never under itself or
 * anything below it (`CategoryLoop`), and placed **last among its new siblings in every store**
 * (amendment 16(d)), each store's admins dragging it into place there afterwards. Its slugs do
 * not change — an address names the category, not its path — so its addresses stay as they were.
 * **Its path does**: the listing rows of the products in it and below it are written again (§5.4),
 * under the products' lock, taken first, as every change to them. `CategoryMoved` tells the modules
 * above (amendment 16(i)): Pricing's category discounts read what is below a category. **Under a
 * new parent, it and everything under it take the parent's switches — who sees them — in every
 * store** (§1.13, P24), as switching the parent would have given them.
 */
final readonly class MoveCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
        private CategoryInput $input,
        private ProductRepository $products,
        private ListingRows $listingRows,
        private ProductEvents $events,
        private CategoryAudiences $audiences,
    ) {}

    /**
     * @throws CategoryHoldsProducts|CategoryInactive|CategoryLoop|CategoryNotFound|InvalidCatalogAttribute|Unauthorized
     */
    public function handle(MoveCategory $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->runAfterProducts(ListLocks::CATEGORIES, function () use ($command): array {
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
            $placed = $this->input->placeLast($category->id(), $parentId);
            $branch = [$category->id(), ...$this->categories->idsBelow($category->id())];
            // Under its new parent, the branch takes the parent's switches in every store (P24), before
            // its products' rows are written; moved to the top, it keeps its own.
            $audiences = $parentId === null ? [] : $this->audiences->takeParents($parentId, $branch);
            $this->listingRows->refresh($this->products->idsInCategories($branch));
            $this->events->categoryMoved($category->id());

            $entry = ListAudit::changed('category', 'moved', $category->id(), $changes, $category->snapshot());

            return [null, [...($entry === null ? [] : [$entry]), ...$placed, ...$audiences]];
        });
    }
}
