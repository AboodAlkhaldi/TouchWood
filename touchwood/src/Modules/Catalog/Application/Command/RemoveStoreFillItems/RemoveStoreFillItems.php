<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RemoveStoreFillItems;

/**
 * Items taken off a store file's page — not wanted, or a code that cannot be mended (catalog.md §1.3).
 */
final readonly class RemoveStoreFillItems
{
    /**
     * @param  array<array-key, mixed>  $itemIds
     */
    public function __construct(
        public string $importId,
        public array $itemIds,
    ) {}
}
