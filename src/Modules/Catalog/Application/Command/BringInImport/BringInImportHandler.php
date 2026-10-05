<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\BringInImport;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\CatalogCheck;
use Modules\Catalog\Application\Import\CatalogNames;
use Modules\Catalog\Application\Import\FileProblems;
use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\ImportName;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Application\Import\ImportQueue;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\ImportUndecided;
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
 * **Bringing an import's products in: the confirm** (catalog.md §1.12, page part 3; amendment 7(c)):
 * `catalog.import.run`. The catalog may have changed since the file came, so its names and codes are
 * asked again first — a name the catalog now has leaves the list, a name it now lacks joins it, a
 * product whose code another product now holds waits for a decision — and kept. While anything waits
 * the Super Admin is told how much (`ImportUndecided`); otherwise the import is bringing in, audited,
 * and the work is queued. Started again after it failed.
 */
final readonly class BringInImportHandler
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private ImportQueue $queue,
        private PlatformApi $platform,
        private BrandRepository $brands,
        private CategoryRepository $categories,
        private AttributeRepository $attributes,
        private WarrantyRepository $warranties,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws ImportClosed|ImportUndecided|ListItemNotFound|Unauthorized
     */
    public function handle(BringInImport $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        // The names and codes asked again are kept whether or not anything still waits, so the page
        // shows what to decide; the refusal comes after they are saved.
        [$names, $codes] = $this->db->transaction(function () use ($command): array {
            $import = $this->imports->lock($command->importId);

            if ($import === null || $import->kind !== ImportHeader::PRODUCTS) {
                throw new ListItemNotFound($command->importId);
            }

            if (! $import->isDeciding()) {
                throw new ImportClosed;
            }

            $products = $this->imports->products($import->id);
            $this->imports->replaceNames($import->id, CatalogCheck::names(
                array_map(static fn (ImportProduct $product): FileProduct => $product->effective(), $products),
                CatalogNames::load($this->brands, $this->categories, $this->attributes, $this->warranties),
                new FileProblems,
            ));
            $this->imports->recordConflicts($this->holders($products));

            $names = count(array_filter($this->imports->names($import->id), static fn (ImportName $name): bool => $name->decision === null));
            $codes = count(array_filter($this->imports->products($import->id), static fn (ImportProduct $product): bool => $product->conflictProductId !== null && $product->decision === null));

            if ($names === 0 && $codes === 0) {
                $this->imports->start($import->id);
                $this->queue->bringIn($import->id);
                $now = ['state' => ImportHeader::BRINGING_IN, 'products' => count($products)];
                $this->platform->recordAudit(ListAudit::changed('import', 'bringing_in', $import->id, ['state' => $import->state, 'products' => null], $now) ?? throw new LogicException('No change to record.'));
            }

            return [$names, $codes];
        }, 3);

        if ($names > 0 || $codes > 0) {
            throw new ImportUndecided($names, $codes);
        }
    }

    /**
     * The catalog's product holding each one's codes now, one at most: the file was refused for two.
     *
     * @param  list<ImportProduct>  $products
     * @return array<string, string|null>
     */
    private function holders(array $products): array
    {
        $holders = $this->imports->codeHolders(array_values(array_unique(array_merge(...array_map(static fn (ImportProduct $product): array => $product->codes, $products)))));
        $found = [];

        foreach ($products as $product) {
            $held = array_values(array_unique(array_filter(array_map(static fn (string $code): ?string => $holders[$code] ?? null, $product->codes))));
            $found[$product->id] = $held[0] ?? null;
        }

        return $found;
    }
}
