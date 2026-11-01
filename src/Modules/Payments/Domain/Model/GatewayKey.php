<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;

/**
 * One key of a store's gateway as it may be shown (payments.md §1.2): never the key or the secret, only
 * its last 6 characters, its expiry date, when it was set, and whether MyFatoorah has refused it.
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
        public bool $rejected,
        public DateTimeImmutable $setAt,
    ) {}
}
