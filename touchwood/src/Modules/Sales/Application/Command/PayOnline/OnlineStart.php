<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PayOnline;

use Modules\Payments\Public\Dto\PaymentStartDto;

/** What the page follows after Pay (payments.md §1.4 steps 5–9). */
final readonly class OnlineStart
{
    public function __construct(
        public string $tryId,
        public PaymentStartDto $start,
    ) {}
}
