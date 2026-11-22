<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UploadStoreFill;

/**
 * An admins' store file uploaded on its store's page (catalog.md §1.3; amendment 6(g)).
 */
final readonly class UploadStoreFill
{
    /**
     * @param  string  $path  where the uploaded file waits, on this server
     * @param  string  $fileName  as the person's computer named it, for the page
     */
    public function __construct(
        public string $storeId,
        public string $path,
        public string $fileName,
    ) {}
}
