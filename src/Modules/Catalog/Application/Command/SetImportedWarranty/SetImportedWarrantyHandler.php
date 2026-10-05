<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedWarranty;

use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Shared\Application\Unauthorized;

/**
 * **One warranty for an import's products** (catalog.md §1.12, amendment 7(c), (d)): an active
 * warranty, kept by its id — warranties' names need not be unique — replacing the one each has, or
 * only for those that have none.
 */
final readonly class SetImportedWarrantyHandler
{
    public const string PERMISSION = ImportedProductsChange::PERMISSION;

    public function __construct(
        private ImportedProductsChange $change,
        private WarrantyRepository $warranties,
    ) {}

    /**
     * @return int how many products it changed
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|Unauthorized
     */
    public function handle(SetImportedWarranty $command): int
    {
        $this->change->authorize();
        $mode = ImportedProductsChange::mode($command->mode, [ImportedProductsChange::REPLACE, ImportedProductsChange::FILL_EMPTY]);
        $warranty = $this->warranties->find($command->warrantyId) ?? throw new ListItemNotFound($command->warrantyId);

        if (! $warranty->isActive()) {
            throw new ListItemInactive;
        }

        $id = $warranty->id();

        return $this->change->run($command->importId, $command->productIds, 'warranty', $id, $mode, static fn (FileProduct $product, ?Product $updates): FileProduct => $mode === ImportedProductsChange::FILL_EMPTY && ($product->warranty !== null || $product->warrantyId !== null || $updates?->warrantyId() !== null)
            ? $product
            : $product->with(['warranty' => null, 'warranty_id' => $id]));
    }
}
