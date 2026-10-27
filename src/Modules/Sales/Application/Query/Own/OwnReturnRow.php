<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/** One of an order's returns, on the order's page (sales.md §1.8). */
final readonly class OwnReturnRow
{
    public function __construct(
        public string $returnId,
        public string $status,
        public DateTimeImmutable $requestedAt,
        /** What the completed return refunds; null before. */
        public ?Money $refundDue,
        /** The store's reason, as staff wrote it, when the return was refused; null otherwise (§4.1 S8, §4.2 B16). */
        public ?string $staffReason = null,
    ) {}
}
