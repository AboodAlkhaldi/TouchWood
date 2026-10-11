<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\Catalog\Application\Command\CorrectStoreFillCode\CorrectStoreFillCode;
use Modules\Catalog\Application\Command\CorrectStoreFillCode\CorrectStoreFillCodeHandler;
use Modules\Catalog\Application\Command\RemoveStoreFillItems\RemoveStoreFillItems;
use Modules\Catalog\Application\Command\RemoveStoreFillItems\RemoveStoreFillItemsHandler;
use Modules\Catalog\Application\Command\SwitchOnStoreFillItems\SwitchOnStoreFillItems;
use Modules\Catalog\Application\Command\SwitchOnStoreFillItems\SwitchOnStoreFillItemsHandler;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFill;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFillHandler;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\StoreFilePages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\Error\DomainError;

/**
 * The admins' store files (catalog.md §1.3, §4.4 S10; amendment 6(g)): a store's list, uploading one,
 * a file's page and what is done there - each through its own handler, which asks for
 * `catalog.listing.fill` in the file's store. A refused file's problems are kept for the list's next
 * page view only (flashed), at most 500, and said under the upload (`ImportRefused`).
 */
final readonly class StoreFilesController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_products', 'catalog::admin_store_files', 'admin'];

    private const string PROBLEMS = 'catalog.store_file_problems';

    /** A refused file names every problem; the page lists this many. */
    private const int PROBLEMS_SHOWN = 500;

    // What asks who is acting - the pages - is taken by each action, never kept here: a controller is
    // kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, StoreFilePages $pages): Response
    {
        $store = $request->query('store');
        $problems = $request->session()->get(self::PROBLEMS);

        return $this->page->render(
            'Catalog/Admin/StoreFiles/Index',
            $pages->files(is_string($store) && trim($store) !== '' ? trim($store) : null, max($request->integer('page', 1), 1), is_array($problems) ? array_values($problems) : null)->toArray(),
            self::WORDS,
        );
    }

    /** A `.json` of `touchwood-store-fill/1` for the store named by its code (`store`); its page opens. */
    public function upload(CatalogFormRequest $request, PlatformApi $platform, UploadStoreFillHandler $handler): RedirectResponse
    {
        $missing = CatalogRefusals::fileNotArrived($request, 'file', 'file');

        if ($missing !== null) {
            return $missing;
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors(['file' => __('catalog::admin_store_files.upload_none')]);
        }

        $code = $request->optionalText('store') ?? '';

        try {
            $id = $handler->handle(new UploadStoreFill($platform->storeByCode($code)->id ?? $code, (string) $file->getRealPath(), $file->getClientOriginalName()));
        } catch (ImportRefused $refused) {
            return back()->withErrors(['file' => FormErrors::message($refused)])->with(self::PROBLEMS, array_slice($refused->problems, 0, self::PROBLEMS_SHOWN));
        } catch (DomainError $error) {
            return CatalogRefusals::back($request, $error, ['file']);
        }

        return redirect("/admin/store-files/{$id}")->with('status', __('catalog::admin_store_files.toast.uploaded'));
    }

    public function show(string $file, StoreFilePages $pages): Response
    {
        return $this->page->render('Catalog/Admin/StoreFiles/Show', $pages->file($file)->toArray(), self::WORDS);
    }

    /** The items ticked (`item_ids[]`), or with none sent, every ready one. */
    public function switchOn(CatalogFormRequest $request, string $file, SwitchOnStoreFillItemsHandler $handler): RedirectResponse
    {
        $ids = $request->input('item_ids');

        try {
            $count = $handler->handle(new SwitchOnStoreFillItems($file, is_array($ids) ? $request->texts('item_ids') : null));
        } catch (DomainError $error) {
            return CatalogRefusals::back($request, $error);
        }

        return back()->with('status', __('catalog::admin_store_files.toast.switched_on', ['count' => $count]));
    }

    public function remove(CatalogFormRequest $request, string $file, RemoveStoreFillItemsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new RemoveStoreFillItems($file, $request->texts('item_ids'))), 'catalog::admin_store_files.toast.removed');
    }

    /** An unknown code corrected, the item checked again. */
    public function correctCode(CatalogFormRequest $request, string $file, string $item, CorrectStoreFillCodeHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new CorrectStoreFillCode($file, $item, $request->text('code'))), 'catalog::admin_store_files.toast.corrected', ['code']);
    }
}
