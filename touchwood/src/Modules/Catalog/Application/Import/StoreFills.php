<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Closure;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Listing\StoreListingChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What every change to an admins' store file shares (catalog.md §1.3; amendment 6(g), (h)):
 * **`catalog.listing.fill` in the file's store** — an admin role's job only —, asked before its items
 * are read: first in some store, so a file of a store the reader does not cover answers as one that
 * does not exist (§7); the products' lock first and then the file's row, as every store change takes them
 * (`StoreListingChange`); its items, the chosen ones of this file or all; the audit in that store.
 */
final readonly class StoreFills
{
    public const string PERMISSION = CatalogPermissions::LISTING_FILL;

    public function __construct(
        private StoreListingChange $change,
        private Imports $imports,
        private Authorizer $authorizer,
    ) {}

    /**
     * The file's store, the job asked there.
     *
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function authorize(string $importId): string
    {
        // The job somewhere, else "not allowed"; then a file of a store not covered is "not found".
        $stores = $this->authorizer->storesWith(self::PERMISSION);

        if ($stores === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $import = $this->imports->header($importId);
        $covered = $stores === null || in_array($import?->storeId, array_map(static fn (StoreId $store): string => $store->value, $stores), true);

        if ($import === null || $import->kind !== ImportHeader::STORE_FILL || $import->storeId === null || ! $covered) {
            throw new ListItemNotFound($importId);
        }

        return $this->change->authorize(self::PERMISSION, $import->storeId)->value;
    }

    /**
     * @template T
     *
     * @param  Closure(ImportHeader, array<string, StoreFillItem>): array{T, list<AuditEntryDto>}  $work  the file and its items by id
     * @return T
     */
    public function run(string $importId, Closure $work): mixed
    {
        return $this->change->run(function () use ($importId, $work): array {
            $import = $this->imports->lock($importId) ?? throw new ListItemNotFound($importId);
            $items = [];

            foreach ($this->imports->items($import->id) as $item) {
                $items[$item->id] = $item;
            }

            return $work($import, $items);
        });
    }

    public function save(StoreFillItem $item): void
    {
        $this->imports->saveItem($item);
    }

    /**
     * @param  array<array-key, mixed>|null  $itemIds
     * @param  array<string, StoreFillItem>  $items
     * @return list<StoreFillItem>
     *
     * @throws InvalidCatalogAttribute|ListItemNotFound
     */
    public static function chosen(?array $itemIds, array $items): array
    {
        if ($itemIds === null) {
            return array_values($items);
        }

        if ($itemIds === [] || count($itemIds) > StoreFillFile::MAX_ITEMS) {
            throw new InvalidCatalogAttribute('item_ids', 'from 1 to '.number_format(StoreFillFile::MAX_ITEMS).' items, or all');
        }

        $chosen = [];

        foreach ($itemIds as $id) {
            $id = is_string($id) ? strtolower($id) : throw new InvalidCatalogAttribute('item_ids', "the file's items, by their ids");
            $chosen[$id] = $items[$id] ?? throw new ListItemNotFound($id);
        }

        return array_values($chosen);
    }
}
