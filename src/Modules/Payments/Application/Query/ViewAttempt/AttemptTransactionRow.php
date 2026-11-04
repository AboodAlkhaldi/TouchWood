<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ViewAttempt;

use DateTimeImmutable;

/** One transaction of an attempt, as staff read it (payments.md §4.2): nothing of the card but its brand (rule 10). */
final readonly class AttemptTransactionRow
{
    public function __construct(
        public string $id,
        public string $gatewayPaymentId,
        public string $status,
        public bool $succeeded,
        public string $method,
        public ?string $cardBrand,
        public ?int $amountMinor,
        public ?string $amountText,
        public ?string $currencyCode,
        public ?DateTimeImmutable $paidAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public bool $mismatch,
        public bool $paidTwice,
        public DateTimeImmutable $seenAt,
    ) {}
}
