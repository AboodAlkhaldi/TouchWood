<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\UnwireStore;

/**
 * A Super Admin removes a store's wiring (sync.md §1.8), having seen how many sizes will stop being
 * orderable.
 */
final readonly class UnwireStore
{
    public function __construct(
        public string $storeId,
    ) {}
}
