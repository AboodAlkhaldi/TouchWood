<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Shared\Domain\ValueObject\Money;

/**
 * **One transaction**, not one attempt (payments.md §1.1 rule 6, §2.1): read by Sales when an event arrives with
 * ids only. `amount` is what was really paid, `expected` what was asked; `method` our own plain name (gap 9.2);
 * `reference` the gateway's payment id; `paidAt` its `TransactionDate`.
 */
final readonly class PaymentDto
{
    public function __construct(
        public string $paymentId,
        public string $attemptId,
        public string $ownerId,
        public PaymentOwnerKind $ownerKind,
        public string $orderId,
        public Money $amount,
        public Money $expected,
        public string $method,
        public string $reference,
        public PaymentMode $mode,
        public bool $succeeded,
        public ?DateTimeImmutable $paidAt,
        public ?string $failureCode,
        public bool $mismatch,
        public bool $paidTwice,
    ) {}
}
