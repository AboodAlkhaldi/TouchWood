<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PayOnline;

/** Pay pressed (§9.5 #4): the quote and the session, **never an amount** (payments.md rule 12). */
final readonly class PayOnline
{
    public function __construct(
        public string $storeId,
        public string $quoteId,
        public string $sessionId,
        public string $locale,
        public string $returnUrl,
    ) {}
}
