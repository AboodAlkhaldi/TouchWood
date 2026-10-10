<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrand;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrandHandler;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrand;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrandHandler;
use Modules\Catalog\Application\Command\EditBrand\EditBrand;
use Modules\Catalog\Application\Command\EditBrand\EditBrandHandler;
use Modules\Catalog\Application\Command\MakeBrandDefault\MakeBrandDefault;
use Modules\Catalog\Application\Command\MakeBrandDefault\MakeBrandDefaultHandler;
use Modules\Catalog\Application\Command\UploadBrandLogo\UploadBrandLogo;
use Modules\Catalog\Application\Command\UploadBrandLogo\UploadBrandLogoHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\ListPages;

/**
 * The brands screen (catalog.md §1.6, §4.4 S1): the list, and every change made to it. Each handler
 * asks for `catalog.brand.manage` with All stores (§3); this checks nothing more.
 */
final readonly class BrandsController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_brands', 'admin'];

    /** The form's fields, where a refusal naming one is said. */
    private const array FIELDS = [
        'name_ar', 'name_en', 'slug_ar', 'slug_en', 'agency_type', 'position', 'origin_country',
        'description_ar', 'description_en', 'logo_media_id',
    ];

    // What asks who is acting - the pages, the store choices - is taken by each action, never kept here:
    // a controller is kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, ListPages $pages): Response
    {
        return $this->page->render('Catalog/Admin/Brands/Index', $pages->brands(self::query($request, 'reach'))->toArray(), self::WORDS);
    }

    public function add(CatalogFormRequest $request, AddBrandHandler $handler, UploadBrandLogoHandler $upload): RedirectResponse
    {
        return CatalogRefusals::fileNotArrived($request, 'logo', 'logo_media_id') ?? CatalogRefusals::act($request, fn () => $handler->handle(new AddBrand(
            nameAr: $request->text('name_ar'),
            nameEn: $request->text('name_en'),
            agencyType: $request->text('agency_type'),
            showInDefaultListings: $request->boolean('show_in_default_listings'),
            position: $request->number('position'),
            slugAr: $request->optionalText('slug_ar'),
            slugEn: $request->optionalText('slug_en'),
            descriptionAr: $request->marks('description_ar'),
            descriptionEn: $request->marks('description_en'),
            logoMediaId: self::logo($request, $upload),
            originCountry: $request->optionalText('origin_country'),
        )), 'catalog::admin_brands.toast.added', self::FIELDS);
    }

    public function edit(CatalogFormRequest $request, string $brand, EditBrandHandler $handler, UploadBrandLogoHandler $upload): RedirectResponse
    {
        return CatalogRefusals::fileNotArrived($request, 'logo', 'logo_media_id') ?? CatalogRefusals::act($request, fn () => $handler->handle(new EditBrand(
            brandId: $brand,
            nameAr: $request->text('name_ar'),
            nameEn: $request->text('name_en'),
            agencyType: $request->text('agency_type'),
            showInDefaultListings: $request->boolean('show_in_default_listings'),
            position: $request->number('position'),
            slugAr: $request->optionalText('slug_ar'),
            slugEn: $request->optionalText('slug_en'),
            descriptionAr: $request->marks('description_ar'),
            descriptionEn: $request->marks('description_en'),
            logoMediaId: self::logo($request, $upload),
            originCountry: $request->optionalText('origin_country'),
        )), 'catalog::admin_brands.toast.edited', self::FIELDS);
    }

    public function makeDefault(Request $request, string $brand, MakeBrandDefaultHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new MakeBrandDefault($brand)), 'catalog::admin_brands.toast.default');
    }

    public function activate(Request $request, string $brand, ActivateBrandHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ActivateBrand($brand)), 'catalog::admin_brands.toast.activated');
    }

    /**
     * Every product of the brand, in any stage, given its fate: hidden with it, or moved to another
     * brand — one for all, and a choice per product over it (§1.6).
     */
    public function deactivate(CatalogFormRequest $request, string $brand, DeactivateBrandHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeactivateBrand(
            $brand,
            $request->optionalText('every_product'),
            $request->optionalText('move_to'),
            Fates::of($request->input('products')),
        )), 'catalog::admin_brands.toast.deactivated', ['move_to', 'products', 'choice']);
    }

    public function delete(Request $request, string $brand, DeleteBrandHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeleteBrand($brand)), 'catalog::admin_brands.toast.deleted');
    }

    /**
     * The logo the form saves: a new file uploaded first, under the brands' own job (P5); "remove"
     * none; otherwise the one it has. A file Platform refuses — its type, its size — refuses the form.
     */
    private static function logo(CatalogFormRequest $request, UploadBrandLogoHandler $upload): ?string
    {
        $file = $request->file('logo');

        if ($file instanceof UploadedFile) {
            return $upload->handle(new UploadBrandLogo((string) $file->getRealPath(), $file->getClientOriginalName()));
        }

        return $request->boolean('remove_logo') ? null : $request->optionalText('logo_media_id');
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
