<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\AttachApplicationDocument;

/**
 * One paper under one document type of the open draft (b2b.md §1.4): a second upload replaces the
 * first.
 */
final readonly class AttachApplicationDocument
{
    /**
     * @param  string  $path  the uploaded file on local disk
     * @param  string  $originalFilename  as the person's browser named it
     */
    public function __construct(
        public string $documentTypeId,
        public string $path,
        public string $originalFilename,
    ) {}
}
