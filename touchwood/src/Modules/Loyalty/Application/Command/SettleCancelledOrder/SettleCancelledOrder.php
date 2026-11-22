<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\SettleCancelledOrder;

use Modules\Loyalty\Public\Dto\SettleOrder;

/** An order cancelled before it shipped (loyalty.md §1.8), inside Sales's transaction. */
final readonly class SettleCancelledOrder
{
    public function __construct(
        public SettleOrder $change,
    ) {}
}
