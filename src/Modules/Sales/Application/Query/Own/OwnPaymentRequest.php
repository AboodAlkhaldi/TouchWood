<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/** A request open on the customer's order (payments.md §4.2): "Pay the remaining 45.00 SAR by 15:20", with Pay Now. */
final readonly class OwnPaymentRequest
{
    public function __construct(
        public string $requestId,
        public Money $amount,
        public DateTimeImmutable $expiresAt,
    ) {}
}
