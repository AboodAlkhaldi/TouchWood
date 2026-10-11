<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\Catalog\Application\Command\AcceptImportedProducts\AcceptImportedProducts;
use Modules\Catalog\Application\Command\AcceptImportedProducts\AcceptImportedProductsHandler;
use Modules\Catalog\Application\Command\ArchiveImportedProducts\ArchiveImportedProducts;
use Modules\Catalog\Application\Command\ArchiveImportedProducts\ArchiveImportedProductsHandler;
use Modules\Catalog\Application\Command\BringInImport\BringInImport;
use Modules\Catalog\Application\Command\BringInImport\BringInImportHandler;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodes;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodesHandler;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNames;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNamesHandler;
use Modules\Catalog\Application\Command\DeleteImportedProducts\DeleteImportedProducts;
use Modules\Catalog\Application\Command\DeleteImportedProducts\DeleteImportedProductsHandler;
use Modules\Catalog\Application\Command\DiscardImport\DiscardImport;
use Modules\Catalog\Application\Command\DiscardImport\DiscardImportHandler;
use Modules\Catalog\Application\Command\SetImportedBrand\SetImportedBrand;
use Modules\Catalog\Application\Command\SetImportedBrand\SetImportedBrandHandler;
use Modules\Catalog\Application\Command\SetImportedCategory\SetImportedCategory;
use Modules\Catalog\Application\Command\SetImportedCategory\SetImportedCategoryHandler;
use Modules\Catalog\Application\Command\SetImportedFilters\SetImportedFilters;
use Modules\Catalog\Application\Command\SetImportedFilters\SetImportedFiltersHandler;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWords;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWordsHandler;
use Modules\Catalog\Application\Command\SetImportedSlugs\SetImportedSlugs;
use Modules\Catalog\Application\Command\SetImportedSlugs\SetImportedSlugsHandler;
use Modules\Catalog\Application\Command\SetImportedWarranty\SetImportedWarranty;
use Modules\Catalog\Application\Command\SetImportedWarranty\SetImportedWarrantyHandler;
use Modules\Catalog\Application\Command\UploadImport\UploadImport;
use Modules\Catalog\Application\Command\UploadImport\UploadImportHandler;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\ImportPages;
use Shared\Domain\Error\DomainError;

/**
 * The Super Admin's products import (catalog.md §1.12, §4.4 S11): the files, uploading one, an
 * import's four parts and what is decided and done in them - each through its own handler, which asks
 * for `catalog.import.run`; this opens nothing itself. Refusals are said where the panel says them
 * (`CatalogRefusals`); a refused file's problems are kept for the list's next view only, at most 500.
 */
final readonly class ImportsController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_products', 'catalog::admin_store_files', 'catalog::admin_imports', 'admin'];

    /** Each part is a page of its own (P12; lesson 180). */
    private const array PART_PAGES = ['names' => 'NamesPart', 'codes' => 'CodesPart', 'before' => 'BeforePart', 'products' => 'ProductsPart'];

    private const string PROBLEMS = 'catalog.import_problems';

    private const string PROBLEMS_TOTAL = 'catalog.import_problems_total';

    private const int PROBLEMS_SHOWN = 500;

    // What asks who is acting - the pages - is taken by each action, never kept here: a controller is
    // kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, ImportPages $pages): Response
    {
        $problems = $request->session()->get(self::PROBLEMS);
        $total = $request->session()->get(self::PROBLEMS_TOTAL);

        return $this->page->render(
            'Catalog/Admin/Imports/Index',
            $pages->files(max($request->integer('page', 1), 1), is_array($problems) ? array_values($problems) : null, is_int($total) ? $total : null)->toArray(),
            self::WORDS,
        );
    }

    /** A `.json`, or a `.zip` with `products.json` and its photos; its page opens. */
    public function upload(CatalogFormRequest $request, UploadImportHandler $handler): RedirectResponse
    {
        $missing = CatalogRefusals::fileNotArrived($request, 'file', 'file');

        if ($missing !== null) {
            return $missing;
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors(['file' => __('catalog::admin_store_files.upload_none')]);
        }

        try {
            $id = $handler->handle(new UploadImport((string) $file->getRealPath(), $file->getClientOriginalName()));
        } catch (ImportRefused $refused) {
            return back()->withErrors(['file' => FormErrors::message($refused)])
                ->with(self::PROBLEMS, array_slice($refused->problems, 0, self::PROBLEMS_SHOWN))
                ->with(self::PROBLEMS_TOTAL, count($refused->problems));
        } catch (DomainError $error) {
            return CatalogRefusals::back($request, $error, ['file']);
        }

        return redirect("/admin/imports/{$id}")->with('status', __('catalog::admin_imports.toast.uploaded'));
    }

    public function show(Request $request, string $import, ImportPages $pages): Response
    {
        $part = $request->query('part');
        $page = $pages->import($import, is_string($part) ? $part : null, max($request->integer('page', 1), 1));

        return $this->page->render('Catalog/Admin/Imports/'.self::PART_PAGES[$page->part], $page->toArray(), self::WORDS);
    }

    /** Names: `decisions[]`, each `name_id` and `decision` (EXISTING with `target_id`, CREATE with names, REFUSE). */
    public function names(CatalogFormRequest $request, string $import, DecideImportNamesHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DecideImportNames($import, self::list($request->input('decisions')))), 'catalog::admin_imports.toast.decided', ['name_ar', 'name_en', 'slug_ar', 'slug_en']);
    }

    /** Codes: `decisions[]`, each `product_id`, `decision` (UPDATE, REPLACE, SKIP, RECODE with `new_codes`) and `sale`. */
    public function codes(CatalogFormRequest $request, string $import, DecideImportCodesHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DecideImportCodes($import, self::list($request->input('decisions')))), 'catalog::admin_imports.toast.decided', ['new_codes']);
    }

    public function brand(CatalogFormRequest $request, string $import, SetImportedBrandHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetImportedBrand($import, self::ids($request), $request->text('brand_id'), $request->text('mode'))), 'catalog::admin_imports.toast.changed', ['brand_id']);
    }

    public function category(CatalogFormRequest $request, string $import, SetImportedCategoryHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetImportedCategory($import, self::ids($request), $request->text('category_id'), $request->text('mode'))), 'catalog::admin_imports.toast.changed', ['category_id']);
    }

    public function warranty(CatalogFormRequest $request, string $import, SetImportedWarrantyHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetImportedWarranty($import, self::ids($request), $request->text('warranty_id'), $request->text('mode'))), 'catalog::admin_imports.toast.changed', ['warranty_id']);
    }

    public function filters(CatalogFormRequest $request, string $import, SetImportedFiltersHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetImportedFilters($import, self::ids($request), $request->texts('value_ids'), $request->text('mode'))), 'catalog::admin_imports.toast.changed', ['value_ids']);
    }

    public function words(CatalogFormRequest $request, string $import, SetImportedSearchWordsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetImportedSearchWords($import, self::ids($request), $request->texts('words'), $request->text('mode'))), 'catalog::admin_imports.toast.changed', ['words']);
    }

    /** A product's own web address, where the file's would collide (amendment 8(c)). */
    public function addresses(CatalogFormRequest $request, string $import, string $product, SetImportedSlugsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetImportedSlugs($import, $product, $request->optionalText('slug_ar'), $request->optionalText('slug_en'))), 'catalog::admin_imports.toast.changed', ['slug_ar', 'slug_en']);
    }

    public function bringIn(Request $request, string $import, BringInImportHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new BringInImport($import)), 'catalog::admin_imports.toast.bringing_in');
    }

    /** The ticked products (`product_ids[]`), or with none sent, every ready one. */
    public function accept(CatalogFormRequest $request, string $import, AcceptImportedProductsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new AcceptImportedProducts($import, self::ids($request))), 'catalog::admin_imports.toast.accepted');
    }

    public function archive(CatalogFormRequest $request, string $import, ArchiveImportedProductsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ArchiveImportedProducts($import, self::ids($request))), 'catalog::admin_imports.toast.archived');
    }

    public function delete(CatalogFormRequest $request, string $import, DeleteImportedProductsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeleteImportedProducts($import, self::ids($request))), 'catalog::admin_imports.toast.deleted');
    }

    /** The import, its decisions and its zip gone (amendment 10(b)); the files' list opens. */
    public function discard(Request $request, string $import, DiscardImportHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DiscardImport($import));
        } catch (DomainError $error) {
            return CatalogRefusals::back($request, $error);
        }

        return redirect('/admin/imports')->with('status', __('catalog::admin_imports.toast.discarded'));
    }

    /**
     * The ticked products, or null for all: none sent.
     *
     * @return list<string>|null
     */
    private static function ids(CatalogFormRequest $request): ?array
    {
        return is_array($request->input('product_ids')) ? $request->texts('product_ids') : null;
    }

    /**
     * As posted; anything else is left for the handler to refuse.
     *
     * @return array<array-key, mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
