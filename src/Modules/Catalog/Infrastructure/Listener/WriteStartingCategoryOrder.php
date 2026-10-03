<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Listener;

use Modules\Catalog\Application\Lists\StartingCategoryOrder;
use Modules\Platform\Public\Events\StoreCreated;

/**
 * A store opened later starts with the base store's order of the menu (catalog.md amendment 1(d)).
 */
final readonly class WriteStartingCategoryOrder
{
    public function __construct(
        private StartingCategoryOrder $order,
    ) {}

    public function handle(StoreCreated $event): void
    {
        $this->order->forStore($event->storeId);
    }
}
