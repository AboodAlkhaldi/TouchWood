<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\AddDocumentType;

/**
 * Staff adding a document type to a store's list, required or not (b2b.md §1.3, §3.2).
 */
final readonly class AddDocumentType
{
    public function __construct(
        public string $storeId,
        public string $nameAr,
        public string $nameEn,
        public int $position,
        public bool $required,
    ) {}
}
