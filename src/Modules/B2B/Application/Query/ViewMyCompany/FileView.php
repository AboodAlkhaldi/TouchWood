<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * One paper an application holds under one document type (b2b.md §1.4). Opened through
 * OpenMyApplicationFile by its media id.
 */
final readonly class FileView
{
    public function __construct(
        public string $documentTypeId,
        public ?string $documentTypeNameAr,
        public ?string $documentTypeNameEn,
        public string $mediaId,
        public string $uploadedAt,
        /** A draft's file under a type staff deactivated since: it must be removed before sending (§1.3). */
        public bool $noLongerAccepted,
    ) {}
}
