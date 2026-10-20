<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\VoidMoneyRecord;

/** A payment or a refund entered by mistake voided, with a reason (sales.md §1.9): `PAYMENT` or `REFUND`. */
final readonly class VoidMoneyRecord
{
    public function __construct(
        public string $kind,
        public string $recordId,
        public string $reason,
    ) {}
}
