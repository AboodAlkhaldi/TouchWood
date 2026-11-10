<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UploadPageImage;

/** An image uploaded from W7's upload box (content.md §1.1 rule 10, §4.2): the file on local disk, as it came. */
final readonly class UploadPageImage
{
    public function __construct(
        public ?string $storeId,
        public string $path,
        public string $fileName,
    ) {}
}
