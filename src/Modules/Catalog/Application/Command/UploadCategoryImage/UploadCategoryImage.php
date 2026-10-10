<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UploadCategoryImage;

/**
 * A category's photo chosen on the categories screen, uploaded to Platform's library before the form
 * that uses it is saved (catalog.md §4.4, P5). The form then saves with the photo's id, as every
 * handler takes media ids.
 */
final readonly class UploadCategoryImage
{
    /**
     * @param  string  $path  the uploaded file on local disk
     * @param  string  $fileName  the name on the uploader's computer
     */
    public function __construct(
        public string $path,
        public string $fileName,
    ) {}
}
