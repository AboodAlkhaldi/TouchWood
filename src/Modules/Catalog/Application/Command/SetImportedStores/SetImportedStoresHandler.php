<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedStores;

use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * **The stores for an import's products** (catalog.md §1.12, amendments 7(c), 8(b)): replaced, they
 * become **exactly the stores chosen** — a store kept keeps the price and stock it had there, one
 * taken away goes with them —; filled, they go **only to the products with none**. Stores as the
 * panel names them, on or off.
 */
final readonly class SetImportedStoresHandler
{
    public const string PERMISSION = ImportedProductsChange::PERMISSION;

    public function __construct(
        private ImportedProductsChange $change,
        private PlatformApi $platform,
    ) {}

    /**
     * @return int how many products it changed
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(SetImportedStores $command): int
    {
        $this->change->authorize();
        $mode = ImportedProductsChange::mode($command->mode, [ImportedProductsChange::REPLACE, ImportedProductsChange::FILL_EMPTY]);
        $codes = ImportedProductsChange::storeCodes($command->storeCodes, $this->platform);

        return $this->change->run($command->importId, $command->productIds, 'stores', implode(', ', $codes), $mode, static function (FileProduct $product) use ($codes, $mode): FileProduct {
            if ($mode === ImportedProductsChange::FILL_EMPTY && $product->stores !== []) {
                return $product;
            }

            $stores = [];

            foreach ($codes as $code) {
                $stores[$code] = $product->stores[$code] ?? ['price' => null, 'stock' => null];
            }

            return $product->with(['stores' => $stores]);
        });
    }
}
