<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\DeactivateDocumentType;

use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;

/**
 * Staff deactivating a document type, hidden or greyed out (b2b.md §1.3, §3.2, amendment 5).
 */
final readonly class DeactivateDocumentType
{
    public function __construct(
        public string $typeId,
        public InactiveTypeDisplay $shown,
    ) {}
}
