<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewImport;

/**
 * A products file's page (catalog.md §1.12).
 */
final readonly class ViewImport
{
    public function __construct(
        public string $importId,
    ) {}
}
