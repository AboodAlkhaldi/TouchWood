<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UploadPolicyImage;

/**
 * An image uploaded from a policy's upload box (flags-2026-11.md S3.7; content.md §1.1 rule 10, §4.2), in a store the
 * person may tick: the file on local disk, as it came.
 */
final readonly class UploadPolicyImage
{
    public function __construct(
        public ?string $storeId,
        public string $path,
        public string $fileName,
    ) {}
}
