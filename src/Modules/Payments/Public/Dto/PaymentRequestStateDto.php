<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentRequestState;
use Shared\Domain\ValueObject\Money;

/** A request as its page and the order see it (payments.md §2.1). */
final readonly class PaymentRequestStateDto
{
    public function __construct(
        public string $requestId,
        public string $orderId,
        public Money $amount,
        public DateTimeImmutable $expiresAt,
        public PaymentRequestState $state,
        public string $requestedBy,
        public PaymentMode $mode,
    ) {}
}
