<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\OpenCheckoutPayment;

/** The payment form for a quote (payments.md §1.4 step 0): nothing is held or charged. */
final readonly class OpenCheckoutPayment
{
    public function __construct(
        public string $storeId,
        public string $quoteId,
        public string $locale,
    ) {}
}
