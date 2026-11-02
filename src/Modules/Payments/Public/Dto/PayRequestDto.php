<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * Pay, as Sales asks it inside its own transaction (payments.md §1.4 step 3, §2.1): `amount` from Sales, never
 * the page (rule 12), and equal to the session's. `orderId` is the order's future id for a try.
 */
final readonly class PayRequestDto
{
    public function __construct(
        public string $ownerId,
        public PaymentOwnerKind $ownerKind,
        public string $orderId,
        public ?string $quoteId,
        public StoreId $storeId,
        public string $buyerId,
        public string $sessionId,
        public Money $amount,
        public ?string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public string $locale,
        public string $returnUrl,
    ) {}
}
