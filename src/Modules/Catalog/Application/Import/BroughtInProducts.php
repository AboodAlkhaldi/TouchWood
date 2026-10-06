<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * What accepting, archiving and deleting an import's products share (catalog.md §1.12, page part 4;
 * amendment 6(e), (f)): `catalog.import.run`; the products' lock first, then the import's row, which
 * must be in; the products chosen on the page, of this import, or all; each one's fate kept on its row;
 * one audit entry for the step, beside those of the product handlers it goes through.
 */
final readonly class BroughtInProducts
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private ListLocks $locks,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function authorize(): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
    }

    /**
     * @param  array<array-key, mixed>|null  $productIds  the import's products chosen on the page, or null for all
     * @param  Closure(ImportProduct, bool): ?string  $fate  a product and whether all were chosen => its new state, or null to leave it
     * @param  Closure(string, int): ?AuditEntryDto  $audit  the import and how many products it changed => the step's entry
     * @param  (Closure(list<ImportProduct>, list<ImportProduct>): void)|null  $after  once every chosen product met its fate, with those it changed and every product of the import as it was
     * @return int how many products it changed
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemNotFound
     */
    public function run(string $importId, ?array $productIds, Closure $fate, Closure $audit, ?Closure $after = null): int
    {
        $chosen = self::chosen($productIds);

        return $this->db->transaction(function () use ($importId, $chosen, $fate, $audit, $after): int {
            $this->locks->lock(ListLocks::PRODUCTS);
            $import = $this->imports->lock($importId);

            if ($import === null || $import->kind !== ImportHeader::PRODUCTS) {
                throw new ListItemNotFound($importId);
            }

            if ($import->state !== ImportHeader::IN) {
                throw new ImportClosed;
            }

            $rows = [];

            foreach ($this->imports->products($import->id) as $row) {
                $rows[$row->id] = $row;
            }

            $results = [];
            $changed = [];

            foreach ($chosen ?? array_keys($rows) as $id) {
                $row = $rows[$id] ?? throw new ListItemNotFound($id);
                $state = $fate($row, $chosen === null);

                if ($state !== null) {
                    $results[$row->id] = ['product_id' => $state === 'DELETED' ? null : $row->productId, 'state' => $state];
                    $changed[] = $row;
                }
            }

            if ($results === []) {
                return 0;
            }

            if ($after !== null) {
                $after($changed, array_values($rows));
            }

            $this->imports->recordResults($results);
            $this->platform->recordAudit($audit($import->id, count($results)) ?? throw new LogicException('No change to record.'));

            return count($results);
        }, 3);
    }

    /**
     * @param  array<array-key, mixed>|null  $productIds
     * @return list<string>|null
     *
     * @throws InvalidCatalogAttribute
     */
    private static function chosen(?array $productIds): ?array
    {
        if ($productIds === null) {
            return null;
        }

        if ($productIds === [] || count($productIds) > ProductsFile::MAX_PRODUCTS) {
            throw new InvalidCatalogAttribute('product_ids', 'from 1 to '.number_format(ProductsFile::MAX_PRODUCTS).' products, or all');
        }

        $ids = [];

        foreach ($productIds as $id) {
            $ids[] = is_string($id) ? strtolower($id) : throw new InvalidCatalogAttribute('product_ids', "the import's products, by their ids");
        }

        return array_values(array_unique($ids));
    }
}
