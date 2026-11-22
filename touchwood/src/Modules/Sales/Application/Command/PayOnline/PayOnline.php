<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PayOnline;

use Modules\Sales\Application\Checkout\ShownTerms;

/**
 * Pay pressed (§9.5 #4): the quote and the session, **never an amount** (payments.md rule 12), and whether the terms'
 * box was ticked (flags-2026-11.md S3.3).
 */
final readonly class PayOnline
{
    public function __construct(
        public string $storeId,
        public string $quoteId,
        public string $sessionId,
        public string $locale,
        public string $returnUrl,
        /** "I have read and agree to the Terms and Conditions…": refused unless ticked. */
        public bool $agreedToTerms,
        /** The terms the page showed beside the tick: refused unless they are the store's now (owner, 2026-11-20). */
        public ShownTerms $shownTerms,
    ) {}
}
