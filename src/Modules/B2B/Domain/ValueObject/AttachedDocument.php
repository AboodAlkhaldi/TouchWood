<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use DateTimeImmutable;

/**
 * One uploaded file under one document type (b2b.md §1.4) — **exactly one per type** (owner,
 * 2026-09-27): uploading again replaces it. The file itself is a private Platform media row; this is
 * the application's reference to it.
 */
final readonly class AttachedDocument
{
    public function __construct(
        public string $documentTypeId,
        public string $mediaId,
        public DateTimeImmutable $uploadedAt,
    ) {}
}
