<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

/** The timers of payments.md §1.7, in minutes. */
final readonly class PaymentTimersDto
{
    public function __construct(
        public int $window,
        public int $wait,
        public int $hold,
    ) {}
}
