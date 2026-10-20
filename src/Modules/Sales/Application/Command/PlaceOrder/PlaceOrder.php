<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\PlaceOrder;

/**
 * The quote of this store placed as an order (sales.md §1.3): only its id — the client never sends an
 * amount (handoff §2). Placing it twice places it once.
 */
final readonly class PlaceOrder
{
    public function __construct(
        public string $storeId,
        public string $quoteId,
    ) {}
}
