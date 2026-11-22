<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use Modules\Payments\Public\Enums\OnlinePaymentReason;
use Modules\Payments\Public\Enums\PaymentMode;

/**
 * Whether a store takes online payment now, for this buyer (payments.md §2.1): live or test, or why not.
 */
final readonly class OnlinePaymentDto
{
    public function __construct(
        public bool $available,
        public ?PaymentMode $mode,
        public ?OnlinePaymentReason $reason,
    ) {}
}
