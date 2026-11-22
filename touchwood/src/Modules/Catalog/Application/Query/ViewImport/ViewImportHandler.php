<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewImport;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\ImportAddresses;
use Modules\Catalog\Application\Import\ImportAttributeChanges;
use Modules\Catalog\Application\Import\ImportCodeChanges;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\ImportName;
use Modules\Catalog\Application\Import\ImportNameRow;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Query\Products\CatalogProductReads;
use Modules\Catalog\Application\Query\Products\ProductMissing;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **A products file's page** (catalog.md §1.12): `catalog.import.run`. Its products as they will come
 * in — the page's changes included —; for one brought in and still a draft, what it lacks to be
 * accepted (§1.1). Prices and stock are not kept until stage 5.
 *
 * **Read in batches** (P13): the products brought in through the product page's own core read, many
 * at once, what the drafts lack with their photos in one Platform read (`ProductMissing`), and which
 * catalog products the file would change are on sale in one query - never one an import's product,
 * so a file of 2,000 reads the same few queries as a file of one.
 */
final readonly class ViewImportHandler
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private CatalogProductReads $reads,
        private PlatformApi $platform,
        private ImportAddresses $addresses,
        private ImportCodeChanges $codeChanges,
        private ImportAttributeChanges $attributeChanges,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(ViewImport $query): ImportView
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $import = $this->imports->header($query->importId);

        if ($import === null || $import->kind !== ImportHeader::PRODUCTS) {
            throw new ListItemNotFound($query->importId);
        }

        $rows = $this->imports->products($import->id);
        $names = $this->imports->names($import->id);
        $taken = $import->isDeciding() ? $this->addresses->taken(ImportProduct::takingPart($rows)) : [];
        // Those that would change a code their catalog product keeps (amendment 11(b)).
        $codeChanges = $import->isDeciding() ? array_fill_keys($this->codeChanges->of($import->id, ImportProduct::takingPart($rows)), true) : [];
        // Those the file gives other variant attributes than it may (P33): replace whole, or skip.
        $attributeChanges = $import->isDeciding() ? array_fill_keys($this->attributeChanges->of($import->id, ImportProduct::takingPart($rows)), true) : [];
        $created = array_values(array_filter($names, static fn (ImportName $name): bool => $name->kind === ImportNameRow::CATEGORY && $name->decision === ImportName::CREATE));
        $missing = ProductMissing::ofDrafts($this->platform, $this->reads->cores(array_values(array_unique(array_filter(array_map(static fn (ImportProduct $row): ?string => $row->productId, $rows))))));
        // Whether the catalog's product a waiting one changes is on sale: it then needs keep or take off (amendment 9(c)).
        $waiting = array_filter($rows, static fn (ImportProduct $row): bool => $row->conflictProductId !== null && $row->state === 'WAITING');
        $onSale = array_fill_keys($this->reads->onSaleAmong(array_values(array_unique(array_map(static fn (ImportProduct $row): string => (string) $row->conflictProductId, $waiting)))), true);

        return new ImportView(
            $import->id,
            $import->fileName,
            $import->state,
            $import->failure,
            $import->archive !== null,
            $import->uploadedBy,
            (string) $import->uploadedAt,
            array_map(fn (ImportName $name): ImportNameView => new ImportNameView($name->id, $name->kind, $name->written, $name->attribute, $name->attributeKind?->value, $name->decision, $name->targetId, $name->nameAr, $name->nameEn, $name->products, $name->matches, $name->slugAr, $name->slugEn, $import->isDeciding() && in_array($name, $created, true) && $this->categoryTaken($name, $created)), $names),
            array_map(fn (ImportProduct $row): ImportProductView => self::product($row, $taken[$row->id] ?? [], isset($codeChanges[$row->id]), isset($attributeChanges[$row->id]), $missing, $onSale), $rows),
        );
    }

    /**
     * @param  list<ImportName>  $created  the import's new categories
     */
    private function categoryTaken(ImportName $name, array $created): bool
    {
        try {
            $this->addresses->freeCategory((string) $name->nameAr, (string) $name->nameEn, $name->slugAr, $name->slugEn, array_values(array_filter($created, static fn (ImportName $other): bool => $other->id !== $name->id)), 'name');

            return false;
        } catch (InvalidCatalogAttribute) {
            return true;
        }
    }

    /**
     * @param  list<string>  $addressTaken
     * @param  array<string, list<string>>  $missing  what each draft brought in lacks
     * @param  array<string, true>  $onSale  the catalog products on sale
     */
    private static function product(ImportProduct $row, array $addressTaken, bool $codeChange, bool $attributeChange, array $missing, array $onSale): ImportProductView
    {
        $product = $row->effective();

        return new ImportProductView(
            $row->id,
            $row->number,
            $product->nameAr,
            $product->nameEn,
            $row->codes,
            $row->conflictProductId,
            $row->decision,
            $row->newCodes,
            $row->state,
            $row->productId,
            $row->edited !== null,
            $row->productId === null ? [] : ($missing[strtolower($row->productId)] ?? []),
            $addressTaken,
            $row->conflictProductId !== null && $row->state === 'WAITING' && isset($onSale[strtolower($row->conflictProductId)]),
            $row->sale,
            $row->refusal,
            $codeChange,
            $attributeChange,
        );
    }
}
