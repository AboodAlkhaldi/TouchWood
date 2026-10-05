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
use Modules\Catalog\Application\Import\ImportAddresses;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\ImportName;
use Modules\Catalog\Application\Import\ImportNameRow;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Application\Import\ImportQueue;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\ImportUndecided;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **Bringing an import's products in: the confirm** (catalog.md §1.12, page part 3; amendments 7(c),
 * 8(c)): `catalog.import.run`. The catalog may have changed since the file came, so everything is
 * asked again first, and what changes is kept and audited:
 *
 * - **the names** — one the catalog now has leaves the list, one it now lacks joins it;
 * - **the codes** — a product whose code another product now holds, or no longer holds, waits for a
 *   decision again, and so does one whose new codes another product took meanwhile;
 * - **the addresses** — a new category whose address another took waits again; a product whose
 *   address would collide is counted.
 *
 * While anything waits the Super Admin is told how much (`ImportUndecided`); otherwise the import is
 * bringing in, audited, and the work is queued. Started again after it failed.
 */
final readonly class BringInImportHandler
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private ImportQueue $queue,
        private ImportAddresses $addresses,
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

        // What is asked again is kept whether or not anything still waits, so the page shows what to
        // decide; the refusal comes after it is saved.
        [$names, $codes, $addresses] = $this->db->transaction(function () use ($command): array {
            $import = $this->imports->lock($command->importId);

            if ($import === null || $import->kind !== ImportHeader::PRODUCTS) {
                throw new ListItemNotFound($command->importId);
            }

            if (! $import->isDeciding()) {
                throw new ImportClosed;
            }

            $checked = $this->askAgain($import);
            $names = count(array_filter($this->imports->names($import->id), static fn (ImportName $name): bool => $name->decision === null));
            $rows = $this->imports->products($import->id);
            $codes = count(array_filter($rows, static fn (ImportProduct $row): bool => $row->conflictProductId !== null && $row->decision === null));
            $addresses = count($this->addresses->taken($rows));

            if ($checked !== null) {
                $this->platform->recordAudit($checked);
            }

            if ($names === 0 && $codes === 0 && $addresses === 0) {
                $this->imports->start($import->id);
                $this->queue->bringIn($import->id);
                $now = ['state' => ImportHeader::BRINGING_IN, 'products' => count($rows)];
                $this->platform->recordAudit(ListAudit::changed('import', 'bringing_in', $import->id, ['state' => $import->state, 'products' => null], $now) ?? throw new LogicException('No change to record.'));
            }

            return [$names, $codes, $addresses];
        }, 3);

        if ($names > 0 || $codes > 0 || $addresses > 0) {
            throw new ImportUndecided($names, $codes, $addresses);
        }
    }

    /**
     * The names, codes and new categories' addresses, against the catalog as it is now.
     *
     * @return AuditEntryDto|null what changed, when anything did
     */
    private function askAgain(ImportHeader $import): ?AuditEntryDto
    {
        $rows = $this->imports->products($import->id);
        [$added, $removed] = $this->imports->replaceNames($import->id, CatalogCheck::names(
            array_map(static fn (ImportProduct $row): FileProduct => $row->effective(), $rows),
            CatalogNames::load($this->brands, $this->categories, $this->attributes, $this->warranties),
            new FileProblems,
        ));
        $reopened = $this->imports->recordConflicts($this->holders($rows));
        $recoded = $this->takenNewCodes($this->imports->products($import->id));
        $categories = $this->takenCategoryAddresses($this->imports->names($import->id));
        $this->imports->undecideCodes($recoded);
        $this->imports->undecideNames($categories);
        $now = ['names_added' => $added, 'names_gone' => $removed, 'codes_reopened' => $reopened + count($recoded), 'categories_reopened' => count($categories)];

        return array_sum($now) === 0 ? null : ListAudit::changed('import', 'checked_again', $import->id, array_fill_keys(array_keys($now), null), $now);
    }

    /**
     * The catalog's product holding each one's codes now, one at most: the file was refused for two.
     *
     * @param  list<ImportProduct>  $rows
     * @return array<string, string|null>
     */
    private function holders(array $rows): array
    {
        $holders = $this->imports->codeHolders(array_values(array_unique(array_merge(...array_map(static fn (ImportProduct $row): array => $row->codes, $rows)))));
        $found = [];

        foreach ($rows as $row) {
            $held = array_values(array_unique(array_filter(array_map(static fn (string $code): ?string => $holders[$code] ?? null, $row->codes))));
            $found[$row->id] = $held[0] ?? null;
        }

        return $found;
    }

    /**
     * The products given new codes that a catalog product took meanwhile.
     *
     * @param  list<ImportProduct>  $rows
     * @return list<string>
     */
    private function takenNewCodes(array $rows): array
    {
        $taken = [];

        foreach ($rows as $row) {
            if ($row->decision === ImportProduct::RECODE && $this->imports->codeHolders(array_values($row->newCodes ?? [])) !== []) {
                $taken[] = $row->id;
            }
        }

        return $taken;
    }

    /**
     * The new categories whose address another category took meanwhile.
     *
     * @param  list<ImportName>  $names
     * @return list<string>
     */
    private function takenCategoryAddresses(array $names): array
    {
        $created = array_values(array_filter($names, static fn (ImportName $name): bool => $name->kind === ImportNameRow::CATEGORY && $name->decision === ImportName::CREATE));
        $taken = [];

        foreach ($created as $name) {
            try {
                $this->addresses->freeCategory((string) $name->nameAr, (string) $name->nameEn, $name->slugAr, $name->slugEn, array_values(array_filter($created, static fn (ImportName $other): bool => $other->id !== $name->id)), 'name');
            } catch (InvalidCatalogAttribute) {
                $taken[] = $name->id;
            }
        }

        return $taken;
    }
}
