<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ActivateDocumentType;

/**
 * Staff offering a deactivated document type again (b2b.md §3.2, amendment 10(c)).
 */
final readonly class ActivateDocumentType
{
    public function __construct(
        public string $typeId,
    ) {}
}
