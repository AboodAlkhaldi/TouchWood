<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\UploadReturnPhoto;

final readonly class UploadReturnPhoto
{
    public function __construct(
        public string $returnId,
        public string $path,
        public string $originalFilename,
    ) {}
}
