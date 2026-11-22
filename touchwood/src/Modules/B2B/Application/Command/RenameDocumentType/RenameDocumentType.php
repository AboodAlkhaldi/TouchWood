<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RenameDocumentType;

/**
 * Staff renaming a document type (b2b.md §3.2).
 */
final readonly class RenameDocumentType
{
    public function __construct(
        public string $typeId,
        public string $nameAr,
        public string $nameEn,
    ) {}
}
