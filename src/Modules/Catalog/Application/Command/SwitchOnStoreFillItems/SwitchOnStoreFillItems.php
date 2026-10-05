<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SwitchOnStoreFillItems;

/**
 * Switching on, in a store file's store, the items chosen — or every one ready (catalog.md §1.3;
 * amendment 6(g)).
 */
final readonly class SwitchOnStoreFillItems
{
    /**
     * @param  array<array-key, mixed>|null  $itemIds  the file's items chosen on the page, or null for every one ready
     */
    public function __construct(
        public string $importId,
        public ?array $itemIds,
    ) {}
}
