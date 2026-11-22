<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\UploadTransferDocument;

/** A bank-transfer document a customer uploads on their own order (sales.md §1.9): the file on local disk. */
final readonly class UploadTransferDocument
{
    public function __construct(
        public string $orderId,
        public string $path,
        public string $originalFilename,
    ) {}
}
