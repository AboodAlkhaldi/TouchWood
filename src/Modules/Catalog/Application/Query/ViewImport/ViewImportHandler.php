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
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **A products file's page** (catalog.md §1.12): `catalog.import.run`. Its products as they will come
 * in — the page's changes included —; for one brought in and still a draft, what it lacks to be
 * accepted (§1.1). Prices and stock are not kept until stage 5.
 */
final readonly class ViewImportHandler
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private ProductRepository $products,
        private Readiness $readiness,
        private ImportAddresses $addresses,
        private StoreListingRepository $listings,
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

        return new ImportView(
            $import->id,
            $import->fileName,
            $import->state,
            $import->failure,
            $import->archive !== null,
            $import->uploadedBy,
            (string) $import->uploadedAt,
            array_map(fn (ImportName $name): ImportNameView => new ImportNameView($name->id, $name->kind, $name->written, $name->attribute, $name->attributeKind?->value, $name->decision, $name->targetId, $name->nameAr, $name->nameEn, $name->products, $name->matches, $name->slugAr, $name->slugEn, $import->isDeciding() && in_array($name, $created, true) && $this->categoryTaken($name, $created)), $names),
            array_map(fn (ImportProduct $row): ImportProductView => $this->product($row, $taken[$row->id] ?? [], isset($codeChanges[$row->id]), isset($attributeChanges[$row->id])), $rows),
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
     */
    private function product(ImportProduct $row, array $addressTaken, bool $codeChange, bool $attributeChange): ImportProductView
    {
        $product = $row->effective();
        $draft = $row->productId === null ? null : $this->products->find($row->productId);
        $missing = $draft !== null && $draft->isDraft() ? $this->readiness->missing($draft) : [];
        // Whether the catalog's product it changes is on sale: it then needs keep or take off (amendment 9(c)).
        $onSale = $row->conflictProductId !== null && $row->state === 'WAITING' && $this->listings->activeStoresOf($row->conflictProductId) !== [];

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
            $missing,
            $addressTaken,
            $onSale,
            $row->sale,
            $row->refusal,
            $codeChange,
            $attributeChange,
        );
    }
}
