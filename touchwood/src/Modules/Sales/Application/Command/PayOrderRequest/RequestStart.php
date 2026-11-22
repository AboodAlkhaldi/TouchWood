<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PayOrderRequest;

use Modules\Payments\Public\Dto\PaymentStartDto;

/** Pay pressed on a request's page: the request being paid, and what the charge answered. */
final readonly class RequestStart
{
    public function __construct(
        public string $requestId,
        public PaymentStartDto $start,
    ) {}
}
