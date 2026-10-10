<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\ProductPages;
use Shared\Domain\Error\DomainError;

/**
 * The products list and a product's page (catalog.md §4.4 S8, S9): reading them, and adding a
 * product. The changes each tab makes are in `ProductChangesController`. Each handler asks for its own
 * job; this checks nothing more.
 */
final readonly class ProductsController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_products', 'admin'];

    /**
     * Each tab is a page of its own, the product above it (Products/shell.tsx): the browser loads the
     * open tab's script only, and the server renders it whole.
     *
     * @var array<string, string>
     */
    private const array TAB_PAGES = [
        'details' => 'DetailsTab',
        'variants' => 'VariantsTab',
        'photos' => 'PhotosTab',
        'search' => 'SearchTab',
        'related' => 'RelatedTab',
    ];

    // What asks who is acting - the pages - is taken by each action, never kept here: a controller is
    // kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, ProductPages $pages): Response
    {
        return $this->page->render('Catalog/Admin/Products/Index', $pages->list(
            self::query($request, 'q'),
            self::query($request, 'stage'),
            self::query($request, 'category'),
            self::query($request, 'brand'),
            self::query($request, 'store'),
            self::query($request, 'state'),
            self::query($request, 'after'),
        )->toArray(), self::WORDS);
    }

    public function show(Request $request, string $product, ProductPages $pages): Response
    {
        $page = $pages->product($product, self::query($request, 'tab') ?? 'details', self::query($request, 'find'));

        return $this->page->render('Catalog/Admin/Products/'.(self::TAB_PAGES[$page->tab] ?? 'DetailsTab'), $page->toArray(), self::WORDS);
    }

    /** A draft, and its page opens (S8): no store is asked (amendment 13(f)). */
    public function create(CatalogFormRequest $request, CreateProductHandler $handler): RedirectResponse
    {
        try {
            $id = $handler->handle(new CreateProduct(
                nameAr: $request->text('name_ar'),
                nameEn: $request->optionalText('name_en'),
                brandId: $request->optionalText('brand_id'),
            ));
        } catch (DomainError $error) {
            // The dialog has the names only: a refusal of a web address made from them is said at its top.
            return CatalogRefusals::back($request, $error, ['name_ar', 'name_en']);
        }

        return redirect()->route('catalog.admin.products.show', ['product' => $id])->with('status', __('catalog::admin_products.toast.created'));
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
