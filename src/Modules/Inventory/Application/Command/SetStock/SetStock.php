<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetStock;

/**
 * A stocktake ("Set to", inventory.md §1.5, §3): one size's counted number, or several at once.
 */
final readonly class SetStock
{
    /**
     * @param  array<string, int>  $counts  variant id => the number counted
     */
    public function __construct(
        public string $storeId,
        public array $counts,
    ) {}
}
