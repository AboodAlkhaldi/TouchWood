<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use DateTimeImmutable;

/**
 * The charge, with the session the form filled (payments.md §1.4 step 4). The amount is a decimal, as
 * `Money::toDecimal` writes it; our attempt's id goes as `ExternalIdentifier`, signed in the notice (rule 7).
 */
final readonly class ChargeRequest
{
    public function __construct(
        public string $sessionId,
        public string $amount,
        public string $currencyCode,
        public string $idempotencyKey,
        public string $attemptId,
        public string $ownerId,
        public DateTimeImmutable $paymentExpiry,
        public string $locale,
        public string $returnUrl,
        public string $noticeUrl,
    ) {}
}
