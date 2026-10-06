<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\BringInImport;

/**
 * The Super Admin's confirm on an import's page (catalog.md §1.12, page part 3; amendment 7(c)): bring
 * its products in.
 */
final readonly class BringInImport
{
    public function __construct(
        public string $importId,
    ) {}
}
