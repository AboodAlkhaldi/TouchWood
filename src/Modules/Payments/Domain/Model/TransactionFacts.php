<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use DateTimeImmutable;

/**
 * A transaction as it is written (payments.md §5): nothing of the card but its brand, and the amount both as
 * the text the gateway sent and, when it could be read exactly, in the smallest unit (rule 8).
 */
final readonly class TransactionFacts
{
    public function __construct(
        public string $attemptId,
        public string $gatewayPaymentId,
        public string $status,
        public bool $succeeded,
        public string $method,
        public ?string $cardBrand,
        public ?int $amountMinor,
        public ?string $amountText,
        public ?string $currencyCode,
        public ?string $baseCurrencyCode,
        public ?DateTimeImmutable $paidAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public bool $mismatch,
        public bool $paidTwice,
        public bool $published,
    ) {}
}
