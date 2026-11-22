<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\SettleEditedOrder;

use Modules\Loyalty\Public\Dto\SettleEdit;

/** A staff edit before shipping that lowered the points discount (loyalty.md §1.8), inside Sales's transaction. */
final readonly class SettleEditedOrder
{
    public function __construct(
        public SettleEdit $change,
    ) {}
}
