<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

/** A payment started (sales.md §2.4): Payments' own id for it, and the gateway's page to send the buyer to. Provisional. */
final readonly class PaymentStartDto
{
    public function __construct(
        public string $paymentId,
        public string $redirectUrl,
    ) {}
}
