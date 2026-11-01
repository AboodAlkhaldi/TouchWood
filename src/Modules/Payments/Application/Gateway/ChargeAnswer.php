<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

final readonly class ChargeAnswer
{
    public function __construct(
        public string $invoiceId,
        public string $paymentId,
        public ?string $frameUrl,
    ) {}
}
