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
     * An admins' store file that passed its checks, OPEN, its items as the file gave them.
     *
     * @param  list<FileItem>  $items
     */
    public function addStoreFill(string $id, string $storeId, string $fileName, ?string $uploadedBy, array $items): void;

    /**
     * @return list<StoreFillItem> in the file's order
     */
    public function items(string $importId): array;

    public function saveItem(StoreFillItem $item): void;

    /**
     * Uploaded files of one kind, newest first — a store's own, for a store file.
     *
     * @return array{list<ImportSummary>, int} this page, and how many there are
     */
    public function summaries(string $kind, ?string $storeId, int $page, int $perPage): array;

    /** The import's own row, read without a lock — to know its store before asking for the job there. */
    public function header(string $importId): ?ImportHeader;

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

    /**
     * Keeps these products as the page's changes left them (amendment 7(c)).
     *
     * @param  list<ImportProduct>  $products
     */
    public function saveEdits(array $products): void;

    /**
     * The names the import's products now use that the catalog lacks: a name still used keeps its
     * decision and takes its new count, one no longer used goes, a new one waits for a decision.
     *
     * @param  list<ImportNameRow>  $names
     * @return array{int, int} how many names joined the list, and how many left it
     */
    public function replaceNames(string $importId, array $names): array;

    /**
     * These products wait for a decision on their codes again.
     *
     * @param  list<string>  $productIds
     */
    public function undecideCodes(array $productIds): void;

    /** A failed bringing in, decided again: deciding once more, its failure kept only in the audit log. */
    public function reopen(string $importId): void;

    /**
     * The catalog's product holding each product's codes, asked again before bringing in: a product
     * whose holder changed waits for a decision again, one whose holder went needs none.
     *
     * @param  array<string, string|null>  $holders  import product id => the catalog's product holding its codes now
     * @return int how many products wait for a decision again
     */
    public function recordConflicts(array $holders): int;

    /** Bringing in has started: the import's names and codes are decided. */
    public function start(string $importId): void;

    /**
     * What became of each product brought in, or held back.
     *
     * @param  array<string, array{product_id: string|null, state: string}>  $results  import product id => its result
     */
    public function recordResults(array $results): void;

    /** The products are in; the zip is let go. */
    public function finish(string $importId): void;

    /** Bringing in failed, nothing kept: why, for the page. */
    public function fail(string $importId, string $failure): void;

    /**
     * The catalog's products holding these web addresses, now or once (§1.1): an address is one
     * product's, ever.
     *
     * @param  list<string>  $slugs
     * @return array<string, list<string>> slug => the products holding it
     */
    public function slugHolders(string $locale, array $slugs): array;

    /**
     * The catalog's products holding these codes, now or once (amendment 3(e)).
     *
     * @param  list<string>  $codes
     * @return array<string, string> code => product id, for the codes some product holds
     */
    public function codeHolders(array $codes): array;
}
