<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\AllowShipUnpaid;

/** Whether staff let an approved order ship before it is paid (sales.md §1.4). */
final readonly class AllowShipUnpaid
{
    public function __construct(
        public string $orderId,
        public bool $allow,
    ) {}
}
