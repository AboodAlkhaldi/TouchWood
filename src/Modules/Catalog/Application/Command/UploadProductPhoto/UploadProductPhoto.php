<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UploadProductPhoto;

/**
 * A photo chosen on a product's page - for its gallery or one of its variants - uploaded to Platform's
 * library before the change that uses it is saved (catalog.md §4.4 S9, P5). The change then saves with
 * the photo's id, as every handler takes media ids.
 */
final readonly class UploadProductPhoto
{
    /**
     * @param  string  $productId  the product the photo is for
     * @param  string  $path  the uploaded file on local disk
     * @param  string  $fileName  the name on the uploader's computer
     */
    public function __construct(
        public string $productId,
        public string $path,
        public string $fileName,
    ) {}
}
