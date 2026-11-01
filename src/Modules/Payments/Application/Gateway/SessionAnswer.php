<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

/**
 * `$payment` is null while the session was never charged (payments.md §9.8: `IsUsed` turns true at the
 * charge, and `TransactionResult` then holds the invoice and the transaction).
 */
final readonly class SessionAnswer
{
    public function __construct(
        public bool $isUsed,
        public ?PaymentAnswer $payment,
    ) {}
}
