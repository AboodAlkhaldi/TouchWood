<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UploadImport;

/**
 * A products file uploaded (catalog.md §1.12): the JSON alone, or a zip holding `products.json` at
 * its top and the photos it names — told apart by the file itself, not its name.
 */
final readonly class UploadImport
{
    /**
     * @param  string  $path  where the uploaded file waits, on this server
     * @param  string  $fileName  as the person's computer named it, for the import's page
     */
    public function __construct(
        public string $path,
        public string $fileName,
    ) {}
}
