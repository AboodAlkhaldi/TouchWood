<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Modules\Platform\Public\Events\StoreCreated;
use Modules\Sales\Domain\Repository\OrderNumbers;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store opened after Sales's tables were migrated — the launch stores the seeder creates included —
 * gets its own order numbers from 1 (sales.md §1.3, §6). A store that has them keeps them.
 */
final readonly class CreateOrderSequence
{
    public function __construct(
        private OrderNumbers $numbers,
    ) {}

    public function handle(StoreCreated $event): void
    {
        $this->numbers->create(StoreId::fromString($event->storeId));
    }
}
