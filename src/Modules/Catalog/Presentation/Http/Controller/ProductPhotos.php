<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Modules\Catalog\Application\Command\UploadProductPhoto\UploadProductPhoto;
use Modules\Catalog\Application\Command\UploadProductPhoto\UploadProductPhotoHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;

/**
 * The new photos a product's form sends (`photos[]`), uploaded in their order under the product's own
 * job before the change that keeps them (catalog.md §4.4 S9, P5). A file that did not arrive whole -
 * larger than the server accepts - refuses the form beside its photos, never a 500.
 */
final class ProductPhotos
{
    /** The refusal for a file that did not arrive whole, or null when every one did. */
    public static function notArrived(CatalogFormRequest $request): ?RedirectResponse
    {
        foreach (self::files($request) as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getRealPath() === false) {
                return back()->withErrors(['photos' => __('catalog::admin.image.not_arrived')]);
            }
        }

        return null;
    }

    /**
     * Uploads them, in order: their media ids. Called inside the change, so Platform's refusal of one
     * (its type, its size) refuses the form.
     *
     * @return list<string>
     */
    public static function upload(CatalogFormRequest $request, string $productId, UploadProductPhotoHandler $upload): array
    {
        $ids = [];

        foreach (self::files($request) as $file) {
            if ($file instanceof UploadedFile) {
                $ids[] = $upload->handle(new UploadProductPhoto($productId, (string) $file->getRealPath(), $file->getClientOriginalName()));
            }
        }

        return $ids;
    }

    /**
     * @return list<mixed>
     */
    private static function files(CatalogFormRequest $request): array
    {
        $files = $request->file('photos');

        return $files === null ? [] : (is_array($files) ? array_values($files) : [$files]);
    }
}
