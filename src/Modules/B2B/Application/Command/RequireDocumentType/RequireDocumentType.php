<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RequireDocumentType;

/**
 * Staff making a document type required, or optional again (b2b.md §1.3, §3.2).
 */
final readonly class RequireDocumentType
{
    public function __construct(
        public string $typeId,
        public bool $required,
    ) {}
}
