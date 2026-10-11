<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\ImportName;
use Modules\Catalog\Application\Import\ImportNameRow;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Application\Import\ImportSummary;
use Modules\Catalog\Application\Query\ListImports\ListImports;
use Modules\Catalog\Application\Query\ListImports\ListImportsHandler;
use Modules\Catalog\Application\Query\Products\AttributeChoice;
use Modules\Catalog\Application\Query\Products\CatalogProductReads;
use Modules\Catalog\Application\Query\Products\ProductCore;
use Modules\Catalog\Application\Query\ViewImport\ImportNameView;
use Modules\Catalog\Application\Query\ViewImport\ImportProductView;
use Modules\Catalog\Application\Query\ViewImport\ImportView;
use Modules\Catalog\Application\Query\ViewImport\ViewImport;
use Modules\Catalog\Application\Query\ViewImport\ViewImportHandler;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Contracts\StaffNames;
use Shared\Application\Unauthorized;

/**
 * The import's pages (catalog.md §4.4 S11) from Catalog's reads, which ask for `catalog.import.run`
 * themselves. Who uploaded a file is named through Platform's `StaffNames`, as the reader may see it.
 * What still waits is counted as Bring Products In counts it, from the same view; the open part's
 * data only is handed to the page.
 */
final readonly class ImportPages
{
    /** The import's page in four parts, each a tab in the address (P12). */
    public const array PARTS = ['names', 'codes', 'before', 'products'];

    /** The products part, a page at a time (P13). */
    public const int PER_PAGE = 100;

    public function __construct(
        private ListImportsHandler $list,
        private ViewImportHandler $view,
        private StaffNames $staff,
        private CatalogProductReads $reads,
    ) {}

    /**
     * @param  list<array{at: string, problem: string}>|null  $problems  the last upload's, refused
     *
     * @throws Unauthorized
     */
    public function files(int $page, ?array $problems, ?int $problemsTotal): ImportsPage
    {
        $list = $this->list->handle(new ListImports($page));
        $names = $this->staff->forReader(array_values(array_unique(array_filter(array_map(static fn (ImportSummary $file): ?string => $file->uploadedBy, $list->imports)))));

        return new ImportsPage(
            array_map(static fn (ImportSummary $file): ImportFileRowData => new ImportFileRowData(
                $file->id,
                $file->fileName,
                $file->uploadedBy === null ? null : ($names[strtolower($file->uploadedBy)] ?? null)?->name,
                $file->uploadedAt,
                $file->count,
                $file->state,
            ), $list->imports),
            $list->page,
            $list->page * $list->perPage < $list->total,
            $problems === null ? null : array_map(static fn (array $problem): ImportProblemData => new ImportProblemData($problem['at'], $problem['problem']), $problems),
            $problems === null ? null : ($problemsTotal ?? count($problems)),
        );
    }

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function import(string $importId, ?string $part, int $page): ImportPage
    {
        $view = $this->view->handle(new ViewImport($importId));
        $deciding = $view->state === ImportHeader::DECIDING;
        $part = in_array($part, self::PARTS, true) ? $part : ($deciding ? 'names' : 'products');
        $page = max($page, 1);
        $taking = array_values(array_filter($view->products, static fn (ImportProductView $row): bool => $row->state !== ImportProduct::REFUSED));
        $uploader = $view->uploadedBy === null ? null : ($this->staff->forReader([$view->uploadedBy])[strtolower($view->uploadedBy)] ?? null)?->name;
        $options = $part === 'names' || $part === 'before' ? $this->reads->options() : null;
        $attributes = $part === 'names' || $part === 'before' ? $this->reads->attributeChoices() : null;
        $products = match ($part) {
            'codes' => array_values(array_filter($taking, static fn (ImportProductView $row): bool => $row->conflictProductId !== null)),
            'before' => $taking,
            'products' => array_slice($view->products, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            default => [],
        };
        // The catalog products the codes part's would change, named and linked.
        $conflicts = $part === 'codes' ? $this->reads->cores(array_values(array_unique(array_map(static fn (ImportProductView $row): string => (string) $row->conflictProductId, $products)))) : [];

        return new ImportPage(
            new ImportHeadData($view->id, $view->fileName, $view->state, $view->failure, $view->withPhotos, $uploader, $view->uploadedAt, count($view->products)),
            $part,
            $deciding ? self::waiting($view, $taking) : new ImportWaitingData(0, 0, 0, 0, 0, 0),
            $part === 'names' ? array_map(static fn (ImportNameView $name): ImportNameData => new ImportNameData(
                $name->id, $name->kind, $name->written, $name->attribute, $name->attributeKind, $name->decision, $name->targetId,
                $name->nameAr, $name->nameEn, $name->products, $name->matches, $name->slugAr, $name->slugEn, $name->addressTaken,
                $name->kind === ImportNameRow::VALUE ? self::valueAttribute($name, $view->names, $attributes ?? []) : null,
            ), $view->names) : null,
            $part === 'names' ? null : array_map(static fn (ImportProductView $row): ImportProductData => self::product($row, $conflicts), $products),
            $part === 'products' ? $page : 1,
            $part === 'products' && $page * self::PER_PAGE < count($view->products),
            $options === null ? null : ProductPages::brands($options),
            $options === null ? null : ProductPages::categories($options),
            $options === null ? null : ProductPages::warranties($options),
            $attributes === null ? null : ProductPages::attributes($attributes),
        );
    }

    /**
     * What still waits, as `BringInImportHandler` counts it from the same rows - held to it by
     * tests/Modules/Catalog/Feature/CatalogImportScreensTest.
     *
     * @param  list<ImportProductView>  $taking  the products taking part (not left out)
     */
    private static function waiting(ImportView $view, array $taking): ImportWaitingData
    {
        return new ImportWaitingData(
            count(array_filter($view->names, static fn (ImportNameView $name): bool => $name->decision === null)),
            count(array_filter($taking, static fn (ImportProductView $row): bool => $row->conflictProductId !== null && $row->decision === null)),
            count(array_filter($taking, static fn (ImportProductView $row): bool => $row->addressTaken !== [])) + count(array_filter($view->names, static fn (ImportNameView $name): bool => $name->addressTaken)),
            count(array_filter($taking, static fn (ImportProductView $row): bool => $row->onSale && $row->sale === null && in_array($row->decision, [ImportProduct::UPDATE, ImportProduct::REPLACE], true))),
            count(array_filter($taking, static fn (ImportProductView $row): bool => $row->codeChange)),
            count(array_filter($taking, static fn (ImportProductView $row): bool => $row->attributeChange)),
        );
    }

    /**
     * A value's attribute as deciding it would find it: an attribute name of the file decided to mean
     * one, else the catalog's attribute of that name - whose values It Means… offers.
     *
     * @param  list<ImportNameView>  $names
     * @param  list<AttributeChoice>  $attributes
     */
    private static function valueAttribute(ImportNameView $value, array $names, array $attributes): ?string
    {
        $key = ImportNameRow::keyOf([(string) $value->attribute]);

        foreach ($names as $name) {
            if ($name->kind === ImportNameRow::ATTRIBUTE && ImportNameRow::keyOf([$name->written]) === $key) {
                return $name->decision === ImportName::EXISTING ? $name->targetId : null;
            }
        }

        foreach ($attributes as $attribute) {
            if (ImportNameRow::keyOf([$attribute->nameEn]) === $key || ImportNameRow::keyOf([$attribute->nameAr]) === $key) {
                return $attribute->id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, ProductCore>  $conflicts
     */
    private static function product(ImportProductView $row, array $conflicts): ImportProductData
    {
        $conflict = $row->conflictProductId === null ? null : ($conflicts[strtolower($row->conflictProductId)] ?? null);

        return new ImportProductData(
            $row->id, $row->number, $row->nameAr, $row->nameEn, $row->codes, $row->conflictProductId,
            $conflict?->nameAr, $conflict?->nameEn, $row->decision, $row->newCodes, $row->state, $row->productId,
            $row->changed, $row->missing, $row->addressTaken, $row->onSale, $row->sale, $row->refusal,
            $row->codeChange, $row->attributeChange,
        );
    }
}
