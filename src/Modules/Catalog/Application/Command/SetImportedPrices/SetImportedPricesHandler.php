<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedPrices;

use Modules\Catalog\Application\Import\FileProblems;
use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Application\Import\ProductsFile;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * **A price and stock for an import's products in one store** (catalog.md §1.12, amendment 8(b)): for
 * the products switched on there only — a store is given to products by the stores' change —,
 * replacing the price or stock they have there, or filling only where they have none. A price, a
 * stock, or both; shown, and kept from stage 5.
 */
final readonly class SetImportedPricesHandler
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
    public function handle(SetImportedPrices $command): int
    {
        $this->change->authorize();
        $mode = ImportedProductsChange::mode($command->mode, [ImportedProductsChange::REPLACE, ImportedProductsChange::FILL_EMPTY]);
        [$code] = ImportedProductsChange::storeCodes([$command->storeCode], $this->platform);
        // As the file's own are read (the guide, §1.1), a form's text taken as the number it writes.
        $price = $command->price === null ? null
            : ProductsFile::price(is_string($command->price) && is_numeric($command->price) ? $command->price + 0 : $command->price, 'price', true, new FileProblems)
                ?? throw new InvalidCatalogAttribute('price', 'a number of at least 0');
        $stock = $command->stock === null ? null
            : ProductsFile::stock(is_string($command->stock) && ctype_digit($command->stock) ? (int) $command->stock : $command->stock, 'stock', new FileProblems)
                ?? throw new InvalidCatalogAttribute('stock', 'a whole number of at least 0');

        if ($price === null && $stock === null) {
            throw new InvalidCatalogAttribute('price', 'a price, a stock, or both');
        }

        $to = $code.($price === null ? '' : " · price {$price}").($stock === null ? '' : " · stock {$stock}");

        return $this->change->run($command->importId, $command->productIds, 'prices', $to, $mode, static function (FileProduct $product) use ($code, $price, $stock, $mode): FileProduct {
            $terms = $product->stores[$code] ?? null;

            if ($terms === null) {
                return $product;
            }

            $stores = $product->stores;
            $stores[$code] = [
                'price' => $price !== null && ($mode === ImportedProductsChange::REPLACE || $terms['price'] === null) ? $price : $terms['price'],
                'stock' => $stock !== null && ($mode === ImportedProductsChange::REPLACE || $terms['stock'] === null) ? $stock : $terms['stock'],
            ];

            return $product->with(['stores' => $stores]);
        });
    }
}
