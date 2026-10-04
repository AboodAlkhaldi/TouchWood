<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateCategory;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\ProductFates;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductReferences;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\CategoryNotLowest;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Enums\ProductFate;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **Deactivating a category** (catalog.md §1.5): every active category below it goes with it, each
 * remembering it went with its parent, so activating it again brings back only what was active
 * before. **Each product in what goes, in any stage, has its fate** (amendment 4(d)) — its own, or
 * the one for all: **hidden** with it, **left** in it (unlisted, still reached), or **moved** to
 * another active lowest category outside what goes — those under a sub-category switched off before
 * included, their earlier choice asked again (amendment 4(g)): left now, one hidden then is no longer
 * hidden. One step, all of it or none of it; each category's and each product's change audited. It changes products, so it takes the products' lock before the
 * categories'.
 */
final readonly class DeactivateCategoryHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_MANAGE;

    public function __construct(
        private SharedListChange $change,
        private CategoryRepository $categories,
        private ProductRepository $products,
        private ProductReferences $references,
        private ProductEvents $events,
    ) {}

    /**
     * @throws CategoryInactive|CategoryNotFound|CategoryNotLowest|InvalidCatalogAttribute|Unauthorized
     */
    public function handle(DeactivateCategory $command): void
    {
        $this->change->authorize(self::PERMISSION);
        $fates = ProductFates::of($command->everyProduct, $command->moveTo, $command->products, ProductFate::cases());

        $this->change->runAfterProducts(ListLocks::CATEGORIES, function () use ($command, $fates): array {
            $category = $this->categories->byId($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);

            // Already deactivated: so is everything below it, and nothing changes.
            if (! $category->isActive()) {
                return [null, []];
            }

            $gone = [];
            $entries = [];

            foreach ([[$category->id(), false], ...array_map(static fn (string $id): array => [$id, true], $this->categories->idsBelow($category->id()))] as [$id, $withParent]) {
                $entry = $this->deactivate($id, $withParent);

                if ($entry !== null) {
                    $gone[] = $id;
                    $entries[] = $entry;
                }
            }

            $reached = $this->products->idsInCategories([$category->id(), ...$this->categories->idsBelow($category->id())]);
            $fates->requireWithin($reached);

            foreach ($reached as $productId) {
                $entry = $this->settle($productId, $fates);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            return [null, $entries];
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

    /**
     * One product's fate. Run after the categories went, so a move into what goes finds it inactive.
     *
     * @throws CategoryInactive|CategoryNotFound|CategoryNotLowest|InvalidCatalogAttribute
     */
    private function settle(string $productId, ProductFates $fates): ?AuditEntryDto
    {
        [$fate, $moveTo] = $fates->for($productId);
        // Read under the products' lock, which this change holds.
        $product = $this->products->byId($productId);

        if ($product === null) {
            return null;
        }

        // Each action written out, so the audit log's names can be checked against the code.
        if ($fate === ProductFate::Leave) {
            $product->hideWithCategory(false);
            $entry = ListAudit::changed('product', 'left', $product->id(), $product->pullChanges(), $product->snapshot());
        } elseif ($fate === ProductFate::Hide) {
            $product->hideWithCategory(true);
            $entry = ListAudit::changed('product', 'hidden', $product->id(), $product->pullChanges(), $product->snapshot());
        } else {
            $target = $this->references->category($moveTo) ?? throw new InvalidCatalogAttribute('move_to', 'where to move it');
            $product->moveToCategory($target);
            $entry = ListAudit::changed('product', 'moved', $product->id(), $product->pullChanges(), $product->snapshot());
        }

        if ($entry !== null) {
            $this->products->update($product);
            $this->events->changed($product);
        }

        return $entry;
    }
}
