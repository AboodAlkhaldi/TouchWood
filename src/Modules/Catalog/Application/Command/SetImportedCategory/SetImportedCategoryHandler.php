<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedCategory;

use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\CategoryNotLowest;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Shared\Application\Unauthorized;

/**
 * **One category for an import's products** (catalog.md §1.12, amendment 7(c), (d)): an active
 * category with no sub-categories (§1.5), kept by its id, replacing the one each has, or only for
 * those that have none — the file giving none, and a catalog product it updates having none when the
 * products are brought in.
 */
final readonly class SetImportedCategoryHandler
{
    public const string PERMISSION = ImportedProductsChange::PERMISSION;

    public function __construct(
        private ImportedProductsChange $change,
        private CategoryRepository $categories,
    ) {}

    /**
     * @return int how many products it changed
     *
     * @throws CategoryInactive|CategoryNotFound|CategoryNotLowest|ImportClosed|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(SetImportedCategory $command): int
    {
        $this->change->authorize();
        $mode = ImportedProductsChange::mode($command->mode, [ImportedProductsChange::REPLACE, ImportedProductsChange::FILL_EMPTY]);
        $category = $this->categories->find($command->categoryId) ?? throw new CategoryNotFound($command->categoryId);

        if (! $category->isActive()) {
            throw new CategoryInactive;
        }

        if ($this->categories->childrenOf($category->id()) !== []) {
            throw new CategoryNotLowest;
        }

        $id = $category->id();

        // Only filling the empty is asked for now and given when brought in (FileProduct::asBroughtIn).
        return $this->change->run($command->importId, $command->productIds, 'category', $id, $mode, static fn (FileProduct $product): FileProduct => match (true) {
            $mode === ImportedProductsChange::REPLACE => $product->with(['category' => null, 'category_id' => $id, 'fill_category_id' => null]),
            $product->category !== null || $product->categoryId !== null || $product->fillCategoryId !== null => $product,
            default => $product->with(['fill_category_id' => $id]),
        });
    }
}
