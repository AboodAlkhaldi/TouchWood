<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * One uploaded file in a list of them (catalog.md §1.12, §1.3): its name, where it stands, how many
 * products or items it holds, who uploaded it and when.
 */
final readonly class ImportSummary
{
    public function __construct(
        public string $id,
        public string $fileName,
        public string $state,
        public int $count,
        public ?string $uploadedBy,
        public string $uploadedAt,
    ) {}
}
