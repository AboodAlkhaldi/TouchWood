<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/** A return in staff's list (sales.md §3 ListReturns). */
final readonly class StaffReturnRow
{
    public function __construct(
        public string $returnId,
        public string $orderId,
        public string $orderNumber,
        public string $storeId,
        public CustomerName $customer,
        public string $status,
        public DateTimeImmutable $requestedAt,
        public ?Money $refundDue,
        public int $exponent,
    ) {}
}
