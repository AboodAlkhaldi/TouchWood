<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * A payment or a refund as Ops' messages about it need it (`SalesReports::moneyRecordForMessage`; ops.md
 * C9b, C10): the record of `PaymentRecorded` or `RefundRecorded`, in the order's currency.
 */
final readonly class MoneyRecordMessageDto
{
    public function __construct(
        public string $moneyRecordId,
        /** `PAYMENT` (money in) or `REFUND` (money out). */
        public string $kind,
        public string $orderId,
        public string $orderNumber,
        public string $storeId,
        public string $customerId,
        public Money $amount,
        /** `BANK_TRANSFER`, `ONLINE`, `OTHER`. */
        public string $way,
        /** The store's own day the money moved, `Y-m-d`. */
        public string $happenedOn,
        /** Voided since: recorded by mistake — a voided payment sends no message (the owner, 2026-10-29). */
        public bool $voided,
    ) {}
}
