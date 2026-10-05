<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedStores;

use Modules\Catalog\Application\Import\FileProblems;
use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Application\Import\ProductsFile;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * **The stores for an import's products** (catalog.md §1.12, amendment 7(c), (d)): each store is
 * added to the stores each product is switched on in when accepted — never one taken away — with
 * the price and stock given, which replace those the product has there, or fill only where it has
 * none. Stores as the panel names them, on or off. Prices and stock are shown, and kept from stage 5.
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
        $codes = $this->codes($command->storeCodes);
        // As the file's own are read (the guide, §1.1), a form's text taken as the number it writes.
        $price = $command->price === null ? null
            : ProductsFile::price(is_string($command->price) && is_numeric($command->price) ? $command->price + 0 : $command->price, 'price', true, new FileProblems)
                ?? throw new InvalidCatalogAttribute('price', 'a number of at least 0');
        $stock = $command->stock === null ? null
            : ProductsFile::stock(is_string($command->stock) && ctype_digit($command->stock) ? (int) $command->stock : $command->stock, 'stock', new FileProblems)
                ?? throw new InvalidCatalogAttribute('stock', 'a whole number of at least 0');

        $to = implode(', ', $codes).($price === null ? '' : " · price {$price}").($stock === null ? '' : " · stock {$stock}");

        return $this->change->run($command->importId, $command->productIds, 'stores', $to, $mode, static function (FileProduct $product) use ($codes, $price, $stock, $mode): FileProduct {
            $stores = $product->stores;

            foreach ($codes as $code) {
                $terms = $stores[$code] ?? ['price' => null, 'stock' => null];
                $stores[$code] = [
                    'price' => $price !== null && ($mode === ImportedProductsChange::REPLACE || $terms['price'] === null) ? $price : $terms['price'],
                    'stock' => $stock !== null && ($mode === ImportedProductsChange::REPLACE || $terms['stock'] === null) ? $stock : $terms['stock'],
                ];
            }

            return $product->with(['stores' => $stores]);
        });
    }

    /**
     * @param  array<array-key, mixed>  $codes
     * @return list<string>
     *
     * @throws InvalidCatalogAttribute
     */
    private function codes(array $codes): array
    {
        $known = array_map(static fn (StoreDto $store): string => $store->code, $this->platform->allStores());

        if ($codes === []) {
            throw new InvalidCatalogAttribute('store_codes', 'at least one store');
        }

        $read = [];

        foreach ($codes as $code) {
            $read[] = is_string($code) && in_array($code, $known, true) ? $code : throw new InvalidCatalogAttribute('store_codes', 'stores as the panel names them');
        }

        return array_values(array_unique($read));
    }
}
