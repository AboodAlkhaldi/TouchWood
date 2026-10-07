<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCode;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCodeHandler;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariant;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariantHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\RestoreProduct\RestoreProduct;
use Modules\Catalog\Application\Command\RestoreProduct\RestoreProductHandler;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariant;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariantHandler;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValues;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValuesHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWords;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWordsHandler;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotos;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotosHandler;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariant;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariantHandler;
use Modules\Catalog\Application\Command\UploadProductPhoto\UploadProductPhotoHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Shared\Domain\Error\DomainError;

/**
 * Every change a product's page makes (catalog.md §4.4 S9), each through its own handler, which asks
 * for its own job - a product's shared data in every store where it is on (§1.1) -; this checks
 * nothing more. Refusals are said where the panel says them (`CatalogRefusals`).
 */
final readonly class ProductChangesController
{
    private const array DETAILS = ['name_ar', 'name_en', 'slug_ar', 'slug_en', 'description_ar', 'description_en'];

    private const array VARIANT = ['code', 'values', 'details', 'number', 'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'position'];

    public function details(CatalogFormRequest $request, string $product, EditProductDetailsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new EditProductDetails(
            productId: $product,
            nameAr: $request->text('name_ar'),
            nameEn: $request->optionalText('name_en'),
            brandId: $request->text('brand_id'),
            slugAr: $request->optionalText('slug_ar'),
            slugEn: $request->optionalText('slug_en'),
            descriptionAr: $request->marks('description_ar'),
            descriptionEn: $request->marks('description_en'),
            categoryId: $request->optionalText('category_id'),
            warrantyId: $request->optionalText('warranty_id'),
            attributeSetId: $request->optionalText('attribute_set_id'),
        )), 'catalog::admin_products.toast.details', self::DETAILS);
    }

    public function addVariant(CatalogFormRequest $request, string $product, AddVariantHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new AddVariant(
            $product,
            $request->text('code'),
            self::map($request->input('values')),
            self::map($request->input('details')),
            $request->optionalNumber('weight_grams'),
            $request->optionalNumber('length_mm'),
            $request->optionalNumber('width_mm'),
            $request->optionalNumber('height_mm'),
            $request->optionalNumber('position') ?? 0,
        )), 'catalog::admin_products.toast.variant_added', self::VARIANT);
    }

    public function editVariant(CatalogFormRequest $request, string $product, string $variant, UpdateVariantHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new UpdateVariant(
            $variant,
            $request->text('code'),
            self::map($request->input('values')),
            self::map($request->input('details')),
            $request->optionalNumber('weight_grams'),
            $request->optionalNumber('length_mm'),
            $request->optionalNumber('width_mm'),
            $request->optionalNumber('height_mm'),
            $request->optionalNumber('position') ?? 0,
        )), 'catalog::admin_products.toast.variant_saved', self::VARIANT);
    }

    /** A ready product's code corrected on every variant holding it (amendment 3(c)). */
    public function correctCode(CatalogFormRequest $request, string $product, string $variant, CorrectVariantCodeHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new CorrectVariantCode($variant, $request->text('code'))), 'catalog::admin_products.toast.code_corrected', ['code']);
    }

    /** A variant's own photos: those kept, in their order, then the new ones (`photos[]`). */
    public function variantPhotos(CatalogFormRequest $request, string $product, string $variant, SetVariantPhotosHandler $handler, UploadProductPhotoHandler $upload): RedirectResponse
    {
        return ProductPhotos::notArrived($request) ?? CatalogRefusals::act($request, fn () => $handler->handle(new SetVariantPhotos(
            $variant,
            [...$request->texts('media_ids'), ...ProductPhotos::upload($request, $product, $upload)],
        )), 'catalog::admin_products.toast.variant_photos', ['photos']);
    }

    public function archiveVariant(Request $request, string $product, string $variant, ArchiveVariantHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ArchiveVariant($variant)), 'catalog::admin_products.toast.variant_archived');
    }

    public function restoreVariant(Request $request, string $product, string $variant, RestoreVariantHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new RestoreVariant($variant)), 'catalog::admin_products.toast.variant_restored');
    }

    public function deleteVariant(Request $request, string $product, string $variant, DeleteDraftVariantHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeleteDraftVariant($variant)), 'catalog::admin_products.toast.variant_deleted');
    }

    /** The gallery: the photos kept, in their order (the first the card's), then the new ones. */
    public function gallery(CatalogFormRequest $request, string $product, SetProductGalleryHandler $handler, UploadProductPhotoHandler $upload): RedirectResponse
    {
        return ProductPhotos::notArrived($request) ?? CatalogRefusals::act($request, fn () => $handler->handle(new SetProductGallery(
            $product,
            [...$request->texts('media_ids'), ...ProductPhotos::upload($request, $product, $upload)],
        )), 'catalog::admin_products.toast.gallery', ['photos']);
    }

    public function searchWords(CatalogFormRequest $request, string $product, SetSearchWordsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetSearchWords($product, $request->texts('words'))), 'catalog::admin_products.toast.search_words', ['search_words']);
    }

    public function filters(CatalogFormRequest $request, string $product, SetFilterValuesHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetFilterValues($product, $request->texts('value_ids'))), 'catalog::admin_products.toast.filters', ['filter_values']);
    }

    /** "You May Also Like" (`RELATED`) or "Goes With" (`GOES_WITH`), in their order. */
    public function related(CatalogFormRequest $request, string $product, string $kind, SetRelationsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new SetRelations($product, strtoupper($kind), $request->texts('product_ids'))), 'catalog::admin_products.toast.related', ['relations']);
    }

    public function ready(Request $request, string $product, MarkProductReadyHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new MarkProductReady($product)), 'catalog::admin_products.toast.ready');
    }

    public function archive(Request $request, string $product, ArchiveProductHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ArchiveProduct($product)), 'catalog::admin_products.toast.archived');
    }

    public function restore(Request $request, string $product, RestoreProductHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new RestoreProduct($product)), 'catalog::admin_products.toast.restored');
    }

    /** A draft gone whole, its codes and addresses free again: back to the list. */
    public function delete(Request $request, string $product, DeleteDraftProductHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DeleteDraftProduct($product));
        } catch (DomainError $error) {
            return CatalogRefusals::back($request, $error);
        }

        return redirect()->route('catalog.admin.products')->with('status', __('catalog::admin_products.toast.deleted'));
    }

    /**
     * A posted map - attribute id => value id, or => a detail - as it came; anything else is none.
     *
     * @return array<array-key, mixed>
     */
    private static function map(mixed $posted): array
    {
        return is_array($posted) ? $posted : [];
    }
}
