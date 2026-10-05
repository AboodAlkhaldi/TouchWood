<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Lists\CategoryInput;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Model\Category;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **Activating a category again** (catalog.md §1.5) brings back exactly what went with it: each
 * category below that was deactivated because its parent went, down to one deactivated on its own,
 * which stays so — and so does everything under that one. **The products hidden with what comes back
 * are shown again**; those under a sub-category still off stay hidden (amendment 4(e)). Under a
 * deactivated parent it is refused: activate the parent first. It changes products, so it takes the
 * products' lock before the categories'.
 */
final readonly class ActivateCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
        private CategoryInput $input,
        private ProductRepository $products,
        private ProductEvents $events,
        private ListingRows $listingRows,
    ) {}

    /**
     * @throws CategoryInactive|CategoryNotFound|Unauthorized
     */
    public function handle(ActivateCategory $command): void
    {
        $this->change->authorize(self::PERMISSION);

        $this->change->runAfterProducts(ListLocks::CATEGORIES, function () use ($command): array {
            $category = $this->categories->byId($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);

            if ($category->isActive()) {
                return [null, []];
            }

            $this->input->parent($category->parentId());

            $entries = [];
            $back = [];
            $this->activate($category, $entries, $back);

            foreach ($this->products->idsHiddenByCategoryIn($back) as $productId) {
                $product = $this->products->byId($productId);

                if ($product === null) {
                    continue;
                }

                $product->hideWithCategory(false);
                $entry = ListAudit::changed('product', 'shown', $product->id(), $product->pullChanges(), $product->snapshot());

                if ($entry !== null) {
                    $this->products->update($product);
                    $this->events->changed($product);
                    $entries[] = $entry;
                }
            }

            // Those left in what comes back are in its pages again, and those hidden are shown.
            $this->listingRows->refresh($this->products->idsInCategories($back));

            return [null, $entries];
        });
    }

    /**
     * @param  list<AuditEntryDto>  $entries
     * @param  list<string>  $back  the categories brought back
     */
    private function activate(Category $category, array &$entries, array &$back): void
    {
        $category->activate();
        $entry = ListAudit::changed('category', 'activated', $category->id(), $category->pullChanges(), $category->snapshot());

        if ($entry !== null) {
            $this->categories->update($category);
            $entries[] = $entry;
            $back[] = $category->id();
        }

        foreach ($this->categories->childrenOf($category->id()) as $child) {
            if (! $child->isActive() && $child->deactivatedWithParent()) {
                $this->activate($child, $entries, $back);
            }
        }
    }
}
