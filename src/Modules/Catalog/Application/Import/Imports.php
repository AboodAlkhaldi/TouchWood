<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * The uploaded files and their pages (catalog.md §5.5): a product file's names to decide and its
 * products, a store file's items. Nothing here is the catalog itself.
 */
interface Imports
{
    public function nextId(): string;

    /**
     * A product file that passed its checks, DECIDING, with its products WAITING.
     *
     * @param  list<ImportNameRow>  $names
     * @param  list<FileProduct>  $products
     * @param  array<int, string>  $conflicts  product number => the catalog's product already holding one of its codes
     */
    public function addProductsImport(string $id, string $fileName, ?string $archive, ?string $uploadedBy, array $names, array $products, array $conflicts): void;

    /**
     * The import's own row, locked to the end of the change, so two changes to one import queue up.
     */
    public function lock(string $importId): ?ImportHeader;

    /**
     * @return list<ImportName> in the order the file first used them
     */
    public function names(string $importId): array;

    public function decideName(ImportName $name): void;

    /**
     * @return list<ImportProduct> in the file's order
     */
    public function products(string $importId): array;

    public function decideCode(ImportProduct $product): void;

    /** A failed bringing in, decided again: deciding once more, its failure kept only in the audit log. */
    public function reopen(string $importId): void;

    /**
     * The catalog's products holding these codes, now or once (amendment 3(e)).
     *
     * @param  list<string>  $codes
     * @return array<string, string> code => product id, for the codes some product holds
     */
    public function codeHolders(array $codes): array;
}
