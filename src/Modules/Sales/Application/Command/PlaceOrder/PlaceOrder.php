<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PlaceOrder;

use Modules\Sales\Application\Checkout\ShownTerms;

/**
 * The quote of this store placed as an order (sales.md §1.3): only its id — the client never sends an
 * amount (handoff §2) — and whether the terms' box was ticked (flags-2026-11.md S3.3). Placing it twice places it once.
 */
final readonly class PlaceOrder
{
    public function __construct(
        public string $storeId,
        public string $quoteId,
        /** "I have read and agree to the Terms and Conditions…": refused unless ticked. */
        public bool $agreedToTerms,
        /** The terms the page showed beside the tick: refused unless they are the store's now (owner, 2026-11-20). */
        public ShownTerms $shownTerms,
    ) {}
}
