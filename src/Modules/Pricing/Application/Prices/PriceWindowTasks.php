<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use DateTimeImmutable;
use Shared\Domain\ValueObject\StoreId;

/**
 * The timed tasks (pricing.md §1.10; owner, 2026-10-09 "alarms + daily check"): one queued for each
 * moment a sale starts or ends, which pushes its sizes' prices into Catalog then. Queued once the
 * change commits, so a change rolled back leaves none; a task whose sale changed meanwhile does nothing.
 */
interface PriceWindowTasks
{
    /**
     * @param  list<DateTimeImmutable>  $moments
     */
    public function queue(StoreId $store, string $saleId, array $moments): void;
}
