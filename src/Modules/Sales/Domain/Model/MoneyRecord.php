<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

use DateTimeImmutable;

/**
 * A payment or a refund recorded against an order (sales.md §1.9, §5): its amount in the order's own
 * currency's smallest unit, the way (`BANK_TRANSFER`, `ONLINE` — stage 7's — or `OTHER` with a note), a
 * reference, the day the money moved, and the form's request id. Voided once at most, never changed.
 */
final readonly class MoneyRecord
{
    public const string PAYMENT = 'PAYMENT';

    public const string REFUND = 'REFUND';

    public function __construct(
        public string $kind,
        public string $id,
        public string $orderId,
        public string $storeId,
        public string $currencyCode,
        public int $amount,
        public string $way,
        public ?string $reference,
        public ?string $note,
        public DateTimeImmutable $happenedOn,
        public string $requestId,
        public ?string $recordedBy,
        public DateTimeImmutable $recordedAt,
        public bool $voided = false,
    ) {}
}
