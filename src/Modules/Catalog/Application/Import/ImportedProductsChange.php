<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * What every change to an import's products before they are brought in shares (catalog.md §1.12,
 * amendment 7(c), (d)) — the brand, warranty or category, the stores with a price and stock, search
 * words, filter values, for all the products or the selected:
 *
 * 1. **`catalog.import.run`**, a Super Admin's, before anything is read.
 * 2. **The import's row locked**, so two changes to one import queue up; refused once bringing in
 *    starts (`ImportClosed`), and a failed bringing in is deciding again.
 * 3. **Each product changed as it now is** — as the page's changes left it, or as the file gave it —
 *    the file's own kept beside it ("all of this just draft": nothing reaches the catalog).
 * 4. **The names list follows the products**: a name no product uses any more goes, a name still used
 *    keeps its decision.
 * 5. **One audit entry** for the change: what, to what, how, and how many products it changed.
 */
final readonly class ImportedProductsChange
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    /** What the products have is replaced. */
    public const string REPLACE = 'REPLACE';

    /** Only the products that have none are given it. */
    public const string FILL_EMPTY = 'FILL_EMPTY';

    /** Added to what each has: search words and filter values. */
    public const string ADD = 'ADD';

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private PlatformApi $platform,
        private BrandRepository $brands,
        private CategoryRepository $categories,
        private AttributeRepository $attributes,
        private WarrantyRepository $warranties,
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
     * @param  list<string>  $modes
     *
     * @throws InvalidCatalogAttribute
     */
    public static function mode(string $mode, array $modes): string
    {
        return in_array($mode, $modes, true) ? $mode : throw new InvalidCatalogAttribute('mode', implode(' or ', $modes));
    }

    /**
     * @param  array<array-key, mixed>|null  $productIds  the import's products chosen on the page, or null for all
     * @param  Closure(FileProduct): FileProduct  $change  the product as it now is => as the change leaves it
     * @return int how many products it changed
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemNotFound
     */
    public function run(string $importId, ?array $productIds, string $what, string $to, string $mode, Closure $change): int
    {
        $chosen = self::chosen($productIds);

        return $this->db->transaction(function () use ($importId, $chosen, $what, $to, $mode, $change): int {
            $import = $this->imports->lock($importId);

            if ($import === null || $import->kind !== ImportHeader::PRODUCTS) {
                throw new ListItemNotFound($importId);
            }

            if (! $import->isDeciding()) {
                throw new ImportClosed;
            }

            $products = [];

            foreach ($this->imports->products($import->id) as $product) {
                $products[$product->id] = $product;
            }

            foreach ($chosen ?? [] as $id) {
                if (! isset($products[$id])) {
                    throw new ListItemNotFound($id);
                }
            }

            $changed = [];

            foreach ($chosen ?? array_keys($products) as $id) {
                $now = $change($products[$id]->effective());

                if ($now->toArray() !== $products[$id]->effective()->toArray()) {
                    $products[$id] = $changed[] = $products[$id]->changedTo($now);
                }
            }

            if ($changed === []) {
                return 0;
            }

            $this->imports->saveEdits($changed);
            // Checked again when brought in, so this list's own problems are not this change's.
            $this->imports->replaceNames($import->id, CatalogCheck::names(
                array_values(array_map(static fn (ImportProduct $product): FileProduct => $product->effective(), $products)),
                CatalogNames::load($this->brands, $this->categories, $this->attributes, $this->warranties),
                new FileProblems,
            ));

            if ($import->state === ImportHeader::FAILED) {
                $this->imports->reopen($import->id);
            }

            $now = ['changed' => $what, 'to' => $to, 'mode' => $mode, 'products' => count($changed)];
            $this->platform->recordAudit(ListAudit::changed('import', 'edited', $import->id, array_fill_keys(array_keys($now), null), $now) ?? throw new LogicException('A change with nothing to record.'));

            return count($changed);
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
            $ids[] = is_string($id) ? strtolower($id) : throw new InvalidCatalogAttribute('product_ids', 'the import\'s products, by their ids');
        }

        return array_values(array_unique($ids));
    }
}
