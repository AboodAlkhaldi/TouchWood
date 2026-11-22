<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\OpenRequestPayment;

final readonly class OpenRequestPayment
{
    public function __construct(
        public string $storeId,
        public string $number,
        public string $locale,
    ) {}
}
