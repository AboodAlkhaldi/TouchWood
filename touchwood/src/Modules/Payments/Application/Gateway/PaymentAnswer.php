<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

/**
 * A payment as MyFatoorah answered it (payments.md §1.1 rules 1, 8; notes §4): every amount the decimal
 * text it sent, never a floating-point number. Nothing of the card but its brand, and nothing of the
 * customer.
 */
final readonly class PaymentAnswer
{
    public function __construct(
        public string $invoiceId,
        public string $invoiceStatus,
        public ?string $externalIdentifier,
        public string $paymentId,
        public string $transactionStatus,
        public ?string $method,
        public ?string $transactionDate,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $cardBrand,
        public ?string $baseCurrency,
        public ?string $baseAmount,
        public ?string $displayCurrency,
        public ?string $displayAmount,
        public ?string $payCurrency,
        public ?string $payAmount,
    ) {}
}
