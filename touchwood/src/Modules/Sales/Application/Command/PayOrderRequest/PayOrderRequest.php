<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PayOrderRequest;

final readonly class PayOrderRequest
{
    public function __construct(
        public string $storeId,
        public string $number,
        public string $sessionId,
        public string $locale,
        public string $returnUrl,
    ) {}
}
