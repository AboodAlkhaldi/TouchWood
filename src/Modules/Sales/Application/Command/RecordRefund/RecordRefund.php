<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RecordRefund;

/** A refund staff record against an order by hand (sales.md §1.9): never above what the order holds. */
final readonly class RecordRefund
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
