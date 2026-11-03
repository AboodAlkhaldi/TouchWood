<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Shared\Domain\ValueObject\Money;

/**
 * One attempt on an order, for staff's Online Payments block (payments.md §2.1, §4.2). `state` is the attempt's own
 * (§4). `method` the successful transaction's, null with none.
 */
final readonly class PaymentAttemptDto
{
    public function __construct(
        public string $attemptId,
        public string $ownerId,
        public PaymentOwnerKind $ownerKind,
        public string $orderId,
        public string $state,
        public Money $amount,
        public PaymentMode $mode,
        public ?string $method,
        public bool $needsAttention,
        public DateTimeImmutable $createdAt,
    ) {}
}
