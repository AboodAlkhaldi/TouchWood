<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * A payment as Payments recorded it (sales.md §2.4): the order it pays, how much, by which method, and
 * the gateway's own reference — what Sales records against the order. Provisional: declared by Sales
 * for stage 7.
 */
final readonly class PaymentDto
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public Money $amount,
        public string $method,
        public ?string $reference,
        public bool $succeeded,
    ) {}
}
