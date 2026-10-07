<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategory;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategoryHandler;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategory;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategoryHandler;
use Modules\Catalog\Application\Command\EditCategory\EditCategory;
use Modules\Catalog\Application\Command\EditCategory\EditCategoryHandler;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategory;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategoryHandler;
use Modules\Catalog\Application\Command\RankCategories\RankCategories;
use Modules\Catalog\Application\Command\RankCategories\RankCategoriesHandler;
use Modules\Catalog\Application\Command\UploadCategoryImage\UploadCategoryImage;
use Modules\Catalog\Application\Command\UploadCategoryImage\UploadCategoryImageHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\ListPages;
use Modules\Platform\Public\Contracts\StoreChoices;
use Shared\Application\Unauthorized;

/**
 * The categories screen (catalog.md §1.5, §4.4 S2): the tree and its changes — `catalog.category.
 * manage` with All stores (§3) — and one store's menu order, `catalog.category.rank` in that store,
 * **the store the page shows** (`?store=`, frontend.md §2.2), sent with the order.
 */
final readonly class CategoriesController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_categories', 'admin'];

    private const array FIELDS = ['name_ar', 'name_en', 'slug_ar', 'slug_en', 'parent_id', 'rank', 'image_media_id'];

    // What asks who is acting - the pages, the store choices - is taken by each action, never kept here:
    // a controller is kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, ListPages $pages): Response
    {
        return $this->page->render(
            'Catalog/Admin/Categories/Index',
            $pages->categories(self::query($request, 'store'), self::query($request, 'reach'))->toArray(),
            self::WORDS,
        );
    }

    public function add(CatalogFormRequest $request, AddCategoryHandler $handler, UploadCategoryImageHandler $upload): RedirectResponse
    {
        return CatalogRefusals::fileNotArrived($request, 'image', 'image_media_id') ?? CatalogRefusals::act($request, fn () => $handler->handle(new AddCategory(
            nameAr: $request->text('name_ar'),
            nameEn: $request->text('name_en'),
            parentId: $request->optionalText('parent_id'),
            rank: $request->number('rank'),
            slugAr: $request->optionalText('slug_ar'),
            slugEn: $request->optionalText('slug_en'),
            imageMediaId: self::image($request, $upload),
        )), 'catalog::admin_categories.toast.added', self::FIELDS);
    }

    public function edit(CatalogFormRequest $request, string $category, EditCategoryHandler $handler, UploadCategoryImageHandler $upload): RedirectResponse
    {
        return CatalogRefusals::fileNotArrived($request, 'image', 'image_media_id') ?? CatalogRefusals::act($request, fn () => $handler->handle(new EditCategory(
            categoryId: $category,
            nameAr: $request->text('name_ar'),
            nameEn: $request->text('name_en'),
            slugAr: $request->optionalText('slug_ar'),
            slugEn: $request->optionalText('slug_en'),
            imageMediaId: self::image($request, $upload),
        )), 'catalog::admin_categories.toast.edited', self::FIELDS);
    }

    /** Another parent, or the top, and its place among the new siblings (amendment 2(c)). */
    public function move(CatalogFormRequest $request, string $category, MoveCategoryHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new MoveCategory(
            $category,
            $request->optionalText('parent_id'),
            $request->number('rank'),
        )), 'catalog::admin_categories.toast.moved', self::FIELDS);
    }

    /**
     * One parent's children in the order dragged, written as places in the store the page shows
     * (amendment 1(d)). A form that names no store, or one not offered here, is refused — never sent
     * to the first store.
     */
    public function rank(CatalogFormRequest $request, RankCategoriesHandler $handler, StoreChoices $choices): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new RankCategories(
            self::storeId($choices, $request->optionalText('store')),
            self::ranks($request->input('ranks')),
        )), 'catalog::admin_categories.toast.ranked', ['rank', 'store']);
    }

    public function activate(Request $request, string $category, ActivateCategoryHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ActivateCategory($category)), 'catalog::admin_categories.toast.activated');
    }

    /**
     * Every product in it and under it, in any stage, given its fate — hidden, left, or moved — one
     * for all and a choice per product over it (§1.5, amendment 4(d), (g)).
     */
    public function deactivate(CatalogFormRequest $request, string $category, DeactivateCategoryHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeactivateCategory(
            $category,
            $request->optionalText('every_product'),
            $request->optionalText('move_to'),
            Fates::of($request->input('products')),
        )), 'catalog::admin_categories.toast.deactivated', ['move_to', 'products', 'choice']);
    }

    public function delete(Request $request, string $category, DeleteCategoryHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeleteCategory($category)), 'catalog::admin_categories.toast.deleted');
    }

    /**
     * @throws Unauthorized when no store is named, or one the reader does not order the menu of
     */
    private static function storeId(StoreChoices $choices, ?string $code): string
    {
        if ($code === null) {
            throw new Unauthorized(CatalogPermissions::CATEGORY_RANK);
        }

        return $choices->chosen($code, CatalogPermissions::CATEGORY_RANK)->id ?? throw new Unauthorized(CatalogPermissions::CATEGORY_RANK);
    }

    /**
     * Category id => its place, as posted; anything else is left for the handler to refuse.
     *
     * @return array<string, mixed>
     */
    private static function ranks(mixed $posted): array
    {
        $ranks = [];

        foreach (is_array($posted) ? $posted : [] as $categoryId => $rank) {
            $ranks[(string) $categoryId] = is_numeric($rank) && (string) (int) $rank === trim((string) $rank) ? (int) $rank : $rank;
        }

        return $ranks;
    }

    private static function image(CatalogFormRequest $request, UploadCategoryImageHandler $upload): ?string
    {
        $file = $request->file('image');

        if ($file instanceof UploadedFile) {
            return $upload->handle(new UploadCategoryImage((string) $file->getRealPath(), $file->getClientOriginalName()));
        }

        return $request->boolean('remove_image') ? null : $request->optionalText('image_media_id');
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
