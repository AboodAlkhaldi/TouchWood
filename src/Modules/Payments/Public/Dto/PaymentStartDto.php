<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentState;

/**
 * What the page does after Pay (payments.md §2.1): `frameUrl` is the bank-code page, null when the method needs
 * none or the gateway could not be reached. `refusedReason` is for staff's eyes, never the customer's.
 */
final readonly class PaymentStartDto
{
    public function __construct(
        public string $attemptId,
        public ?string $frameUrl,
        public DateTimeImmutable $windowEndsAt,
        public PaymentState $state,
        public bool $nothingCharged,
        public ?string $refusedReason = null,
    ) {}
}
