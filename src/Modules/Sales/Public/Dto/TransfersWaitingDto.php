<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** The bank transfers a store still waits for (`SalesReports::transfersWaiting`; ops.md §1.11 item 3). */
final readonly class TransfersWaitingDto
{
    public function __construct(
        public int $orders,
        /** What they still owe together, in the store's currency. */
        public Money $owed,
    ) {}
}
