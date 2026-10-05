<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedBrand;

use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Shared\Application\Unauthorized;

/**
 * **One brand for an import's products** (catalog.md §1.12, amendment 7(c), (d)): an active brand,
 * written as its fixed number — the products then name it as a file may (amendment 7(b)) — replacing
 * the brand each has, or only for those the file gave none.
 */
final readonly class SetImportedBrandHandler
{
    public const string PERMISSION = ImportedProductsChange::PERMISSION;

    public function __construct(
        private ImportedProductsChange $change,
        private BrandRepository $brands,
    ) {}

    /**
     * @return int how many products it changed
     *
     * @throws BrandInactive|BrandNotFound|ImportClosed|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(SetImportedBrand $command): int
    {
        $this->change->authorize();
        $mode = ImportedProductsChange::mode($command->mode, [ImportedProductsChange::REPLACE, ImportedProductsChange::FILL_EMPTY]);
        $brand = $this->brands->find($command->brandId) ?? throw new BrandNotFound($command->brandId);

        if (! $brand->isActive()) {
            throw new BrandInactive;
        }

        $number = array_search($brand->id(), $this->brands->numbers(), true);

        if (! is_int($number)) {
            throw new BrandNotFound($command->brandId);
        }

        return $this->change->run($command->importId, $command->productIds, 'brand', "#{$number}", $mode, static fn (FileProduct $product): FileProduct => $mode === ImportedProductsChange::FILL_EMPTY && ($product->brand !== null || $product->brandNumber !== null)
            ? $product
            : $product->with(['brand' => null, 'brand_number' => $number]));
    }
}
