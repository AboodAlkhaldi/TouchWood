<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\Redeem;

use Modules\Loyalty\Public\Dto\RedeemPoints;

/** Placing an order with points (loyalty.md §1.7), inside Sales's transaction. */
final readonly class Redeem
{
    public function __construct(
        public RedeemPoints $change,
    ) {}
}
