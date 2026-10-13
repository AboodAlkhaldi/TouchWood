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
use Modules\Catalog\Application\Command\OrderCategories\OrderCategories;
use Modules\Catalog\Application\Command\OrderCategories\OrderCategoriesHandler;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiences;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiencesHandler;
use Modules\Catalog\Application\Command\UploadCategoryImage\UploadCategoryImage;
use Modules\Catalog\Application\Command\UploadCategoryImage\UploadCategoryImageHandler;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
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

    private const array FIELDS = ['name_ar', 'name_en', 'slug_ar', 'slug_en', 'parent_id', 'image_media_id'];

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

    /** Another parent, or the top: last among its new siblings in every store (amendment 16(d)). */
    public function move(CatalogFormRequest $request, string $category, MoveCategoryHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new MoveCategory(
            $category,
            $request->optionalText('parent_id'),
        )), 'catalog::admin_categories.toast.moved', self::FIELDS);
    }

    /**
     * One parent's children in the order dropped, or with one moved to the top or the bottom, written
     * as places 1, 2, 3 … in the store the page shows (amendment 16(d)). A form that names no store, or
     * one not offered here, is refused — never sent to the first store. An order has no field of its
     * own on the page, so a refusal is said at its top and in a toast.
     */
    /**
     * One parent's children in the store shown, in their new order (amendment 16(d), P29): the ids
     * dragged, or the one moved to the top, or every other then the one moved to the bottom.
     */
    public function order(CatalogFormRequest $request, OrderCategoriesHandler $handler, StoreChoices $choices): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new OrderCategories(
            self::storeId($choices, $request->optionalText('store')),
            $request->optionalText('parent'),
            $request->texts('ids'),
        )), 'catalog::admin_categories.toast.ranked');
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
     * Who sees the category in the store the page shows (§1.13, amendment 14): the switch flipped —
     * `individuals` or `companies`, each true or false, the other left out — saved the moment it flips
     * (P9), reaching everything under it. A form that names no store, or one not offered here, is
     * refused, as an order is.
     */
    public function audience(CatalogFormRequest $request, string $category, SetCategoryAudiencesHandler $handler, StoreChoices $choices): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetCategoryAudiences(
            self::storeId($choices, $request->optionalText('store'), CatalogPermissions::CATEGORY_AUDIENCE),
            $category,
            self::switch($request->input('individuals')),
            self::switch($request->input('companies')),
        )), 'catalog::admin_categories.toast.audience_set');
    }

    /**
     * @throws Unauthorized when no store is named, or one the reader does not hold the job in
     */
    private static function storeId(StoreChoices $choices, ?string $code, string $permission = CatalogPermissions::CATEGORY_RANK): string
    {
        if ($code === null) {
            throw new Unauthorized($permission);
        }

        return $choices->chosen($code, $permission)->id ?? throw new Unauthorized($permission);
    }

    /**
     * A switch as posted: true or false — `1`, `0`, `true`, `false` — or left out.
     *
     * @throws InvalidCatalogAttribute for anything else
     */
    private static function switch(mixed $posted): ?bool
    {
        if ($posted === null || $posted === '') {
            return null;
        }

        return is_bool($posted) ? $posted : match (is_scalar($posted) ? strtolower(trim((string) $posted)) : '') {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new InvalidCatalogAttribute('audience', 'a switch: on or off'),
        };
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
