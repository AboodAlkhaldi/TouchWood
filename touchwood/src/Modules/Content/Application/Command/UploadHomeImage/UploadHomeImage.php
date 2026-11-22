<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UploadHomeImage;

/** An image uploaded from W1's upload box (content.md §1.1 rule 10, §4.2): the file on local disk, as it came. */
final readonly class UploadHomeImage
{
    public function __construct(
        public ?string $storeId,
        public string $path,
        public string $fileName,
    ) {}
}
