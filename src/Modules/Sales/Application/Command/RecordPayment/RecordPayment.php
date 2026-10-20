<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RecordPayment;

/** A payment staff record against an order by hand (sales.md §1.9): the amount as a decimal in the order's currency, the way, a reference, the day the money moved, the form's request id. */
final readonly class RecordPayment
{
    public function __construct(
        public string $orderId,
        public string $amount,
        public string $way,
        public ?string $reference,
        public ?string $note,
        public string $happenedOn,
        public string $requestId,
    ) {}
}
