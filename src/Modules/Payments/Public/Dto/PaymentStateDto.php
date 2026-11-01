<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentState;

/**
 * What the page shows, and whether Sales may release (payments.md §2.1). `holdEndsAt` is when Pay opens again
 * (§1.7, gap 10.1); `nothingCharged` is the only case in which "No money was taken" may be said (gap 10.2).
 */
final readonly class PaymentStateDto
{
    public function __construct(
        public PaymentState $state,
        public ?DateTimeImmutable $windowEndsAt,
        public ?DateTimeImmutable $waitEndsAt,
        public ?DateTimeImmutable $holdEndsAt,
        public bool $nothingCharged,
        public ?string $attemptId = null,
    ) {}
}
