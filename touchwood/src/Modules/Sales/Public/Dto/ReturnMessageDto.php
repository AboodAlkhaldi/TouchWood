<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** A return as Ops' messages about it need it (`SalesReports::returnForMessage`; ops.md §1.5 C8, C9). */
final readonly class ReturnMessageDto
{
    public function __construct(
        public string $returnId,
        public string $orderId,
        public string $orderNumber,
        public string $storeId,
        public string $customerId,
        /** `REQUESTED`, `ACCEPTED`, `REFUSED`, `WITHDRAWN`, `RECEIVED`, `COMPLETED`, `CLOSED` (§1.8). */
        public string $status,
        /** The store's words when it refused, as staff wrote them; null otherwise. */
        public ?string $staffReason,
        /** What the completed return refunds; null before. */
        public ?Money $refundDue,
    ) {}
}
