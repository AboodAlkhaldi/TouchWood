<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/** A payment or a refund recorded on an order (sales.md §1.9), voided or not. */
final readonly class MoneyRow
{
    public function __construct(
        public string $recordId,
        /** PAYMENT or REFUND. */
        public string $kind,
        public Money $amount,
        public string $way,
        public ?string $reference,
        public ?string $note,
        public DateTimeImmutable $happenedOn,
        public ?string $recordedBy,
        public DateTimeImmutable $recordedAt,
        public ?string $voidedBy,
        public ?DateTimeImmutable $voidedAt,
        public ?string $voidReason,
    ) {}
}
