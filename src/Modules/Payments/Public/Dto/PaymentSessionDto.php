<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;

/**
 * What the page needs to draw the form (payments.md §2.1): `applePay` says this domain is registered in
 * this mode (§1.2).
 */
final readonly class PaymentSessionDto
{
    public function __construct(
        public string $sessionId,
        public string $scriptUrl,
        public PaymentMode $mode,
        public DateTimeImmutable $expiresAt,
        public bool $applePay,
    ) {}
}
