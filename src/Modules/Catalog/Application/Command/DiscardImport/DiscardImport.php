<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DiscardImport;

/**
 * Discarding a products file not brought in (catalog.md §1.12; amendment 10(b)).
 */
final readonly class DiscardImport
{
    public function __construct(
        public string $importId,
    ) {}
}
