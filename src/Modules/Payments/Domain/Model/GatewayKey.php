<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;

/**
 * One key of a store's gateway as it may be shown (payments.md §1.2): never the key or the secret, only
 * its last 6 characters, its expiry date and when it was set.
 */
final readonly class GatewayKey
{
    public function __construct(
        public string $id,
        public string $storeId,
        public PaymentMode $mode,
        public string $last6,
        public DateTimeImmutable $expiresOn,
        public ?int $warnedDays,
        public DateTimeImmutable $setAt,
    ) {}
}
