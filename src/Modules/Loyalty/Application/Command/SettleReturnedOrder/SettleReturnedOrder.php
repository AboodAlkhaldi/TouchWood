<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\SettleReturnedOrder;

use Modules\Loyalty\Public\Dto\SettleReturn;

/** A return completed (loyalty.md §1.8), inside Sales's transaction. */
final readonly class SettleReturnedOrder
{
    public function __construct(
        public SettleReturn $change,
    ) {}
}
