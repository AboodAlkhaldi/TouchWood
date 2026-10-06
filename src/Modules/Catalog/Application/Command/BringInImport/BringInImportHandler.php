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
use Modules\Catalog\Application\Import\ImportCodeChanges;
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
use Modules\Catalog\Domain\Repository\StoreListingRepository;
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
 *   decision again, and so does one whose new codes another product took meanwhile, or whose update
 *   reaches codes two catalog products now hold;
 * - **the addresses** — a new category whose address another took, and a product whose address
 *   would collide, are counted;
 * - **the sales** — a product updated or replaced that is on sale waits for keep on sale or take off
 *   sale (amendment 9(c)).
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
        private StoreListingRepository $listings,
        private ImportCodeChanges $codeChanges,
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
        [$names, $codes, $addresses, $sales, $codeChanges] = $this->db->transaction(function () use ($command): array {
            $import = $this->imports->lock($command->importId);

            if ($import === null || $import->kind !== ImportHeader::PRODUCTS) {
                throw new ListItemNotFound($command->importId);
            }

            if (! $import->isDeciding()) {
                throw new ImportClosed;
            }

            $checked = $this->askAgain($import);
            $names = count(array_filter($this->imports->names($import->id), static fn (ImportName $name): bool => $name->decision === null));
            $rows = ImportProduct::takingPart($this->imports->products($import->id));
            $codes = count(array_filter($rows, static fn (ImportProduct $row): bool => $row->conflictProductId !== null && $row->decision === null));
            $addresses = count($this->addresses->taken($rows)) + count($this->takenCategoryAddresses($this->imports->names($import->id)));
            $sales = count(array_filter($rows, fn (ImportProduct $row): bool => $this->needsSale($row)));
            // A product not a draft keeps its codes (amendment 11(b)): skipped, or the file corrected.
            $codeChanges = count($this->codeChanges->of($import->id, $rows));

            if ($checked !== null) {
                $this->platform->recordAudit($checked);
            }

            if ($names === 0 && $codes === 0 && $addresses === 0 && $sales === 0 && $codeChanges === 0) {
                $this->imports->start($import->id);
                $this->queue->bringIn($import->id);
                $now = ['state' => ImportHeader::BRINGING_IN, 'products' => count($rows)];
                $this->platform->recordAudit(ListAudit::changed('import', 'bringing_in', $import->id, ['state' => $import->state, 'products' => null], $now) ?? throw new LogicException('No change to record.'));
            }

            return [$names, $codes, $addresses, $sales, $codeChanges];
        }, 3);

        if ($names > 0 || $codes > 0 || $addresses > 0 || $sales > 0 || $codeChanges > 0) {
            throw new ImportUndecided($names, $codes, $addresses, $sales, $codeChanges);
        }
    }

    /**
     * The names, codes and new categories' addresses, against the catalog as it is now.
     *
     * @return AuditEntryDto|null what changed, when anything did
     */
    private function askAgain(ImportHeader $import): ?AuditEntryDto
    {
        $rows = ImportProduct::takingPart($this->imports->products($import->id));
        [$added, $removed] = $this->imports->replaceNames($import->id, CatalogCheck::names(
            array_map(static fn (ImportProduct $row): FileProduct => $row->effective(), $rows),
            CatalogNames::load($this->brands, $this->categories, $this->attributes, $this->warranties),
            new FileProblems,
        ));
        $held = $this->holders($rows);
        $reopened = $this->imports->recordConflicts(array_map(static fn (array $ids): ?string => $ids[0] ?? null, $held));
        $stale = $this->staleCodes(ImportProduct::takingPart($this->imports->products($import->id)), $held);
        $this->imports->undecideCodes($stale);
        $now = ['names_added' => $added, 'names_gone' => $removed, 'codes_reopened' => $reopened + count($stale)];

        return array_sum($now) === 0 ? null : ListAudit::changed('import', 'checked_again', $import->id, array_fill_keys(array_keys($now), null), $now);
    }

    /**
     * The catalog's products holding each one's codes now — two, when one took a code since the file
     * came (the file was refused for two).
     *
     * @param  list<ImportProduct>  $rows
     * @return array<string, list<string>>
     */
    private function holders(array $rows): array
    {
        $holders = $this->imports->codeHolders(array_values(array_unique(array_merge(...array_map(static fn (ImportProduct $row): array => $row->codes, $rows)))));
        $found = [];

        foreach ($rows as $row) {
            $found[$row->id] = array_values(array_unique(array_filter(array_map(static fn (string $code): ?string => $holders[$code] ?? null, $row->codes))));
        }

        return $found;
    }

    /**
     * Decisions the catalog no longer allows: an update or a replace of codes two catalog products now
     * hold; new codes another product took meanwhile, or that leave out a code the catalog now holds.
     *
     * @param  list<ImportProduct>  $rows
     * @param  array<string, list<string>>  $held
     * @return list<string>
     */
    private function staleCodes(array $rows, array $held): array
    {
        $stale = [];

        foreach ($rows as $row) {
            if (in_array($row->decision, [ImportProduct::UPDATE, ImportProduct::REPLACE], true) && count($held[$row->id] ?? []) > 1) {
                $stale[] = $row->id;
            } elseif ($row->decision === ImportProduct::RECODE) {
                $codes = $this->imports->codeHolders($row->codes);
                $given = array_map('strval', array_keys($row->newCodes ?? []));

                if (array_diff(array_map('strval', array_keys($codes)), $given) !== [] || $this->imports->codeHolders(array_values($row->newCodes ?? [])) !== []) {
                    $stale[] = $row->id;
                }
            }
        }

        return $stale;
    }

    /**
     * An update or a replace of a product on sale in any store waits for keep on sale or take off
     * sale (amendment 9(c)) — every time, no default.
     */
    private function needsSale(ImportProduct $row): bool
    {
        return in_array($row->decision, [ImportProduct::UPDATE, ImportProduct::REPLACE], true)
            && $row->sale === null
            && $row->conflictProductId !== null
            && $this->listings->activeStoresOf($row->conflictProductId) !== [];
    }

    /**
     * The new categories whose address another category took meanwhile: counted with the addresses,
     * their decision kept, so a new address is all the page asks for.
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
