<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewImport;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\ImportName;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
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
        private PlatformApi $platform,
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

        $stores = array_map(static fn (StoreDto $store): string => $store->code, $this->platform->allStores());

        return new ImportView(
            $import->id,
            $import->fileName,
            $import->state,
            $import->failure,
            $import->archive !== null,
            $import->uploadedBy,
            (string) $import->uploadedAt,
            array_map(static fn (ImportName $name): ImportNameView => new ImportNameView($name->id, $name->kind, $name->written, $name->attribute, $name->attributeKind?->value, $name->decision, $name->targetId, $name->nameAr, $name->nameEn, $name->products), $this->imports->names($import->id)),
            array_map(fn (ImportProduct $row): ImportProductView => $this->product($row, $stores), $this->imports->products($import->id)),
            false,
        );
    }

    /**
     * @param  list<string>  $stores  the panel's store codes
     */
    private function product(ImportProduct $row, array $stores): ImportProductView
    {
        $product = $row->effective();
        $draft = $row->productId === null ? null : $this->products->find($row->productId);
        $missing = $draft !== null && $draft->isDraft() ? $this->readiness->missing($draft) : [];
        $storeViews = [];

        foreach ($product->stores as $code => $terms) {
            $storeViews[] = new ImportStoreView((string) $code, in_array((string) $code, $stores, true), $terms['price'], $terms['stock']);
        }

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
            $storeViews,
        );
    }
}
