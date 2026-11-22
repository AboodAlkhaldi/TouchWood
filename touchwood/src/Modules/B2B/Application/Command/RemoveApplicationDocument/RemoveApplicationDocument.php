<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RemoveApplicationDocument;

/**
 * Takes the paper under one document type out of the open draft (b2b.md §1.4, amendment 5).
 */
final readonly class RemoveApplicationDocument
{
    public function __construct(
        public string $documentTypeId,
    ) {}
}
