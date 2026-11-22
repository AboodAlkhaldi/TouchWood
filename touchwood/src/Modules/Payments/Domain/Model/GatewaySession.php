<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;

/**
 * A session we opened (payments.md §1.1 rule 7): tied to one owner - a quote or a request -, one buyer and
 * one amount in one store and mode, so a page can never pay with another's.
 */
final readonly class GatewaySession
{
    public function __construct(
        public string $id,
        public string $storeId,
        public PaymentMode $mode,
        public string $ownerId,
        public string $buyerId,
        public int $amountMinor,
        public string $currencyCode,
        public DateTimeImmutable $expiresAt,
        public ?string $usedBy = null,
    ) {}
}
