<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\BringInImportProducts;

/**
 * The queued work of bringing an import's products in (catalog.md §1.12, page part 3), once
 * `BringInImport` started it.
 */
final readonly class BringInImportProducts
{
    public function __construct(
        public string $importId,
    ) {}
}
