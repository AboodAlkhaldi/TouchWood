<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\BuyAgain;

final readonly class BuyAgain
{
    public function __construct(
        /** The customer's own order, by its number. */
        public string $orderNumber,
    ) {}
}
