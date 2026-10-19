<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

use Modules\Catalog\Public\Enums\SaleMode;

/**
 * A line that left a cart when its guest signed in or registered (sales.md §1.1, answer 32), and why:
 * `WHOLESALE` — an individual never buys wholesale; `NOT_FOR_YOUR_ACCOUNT` — a product in a category the
 * store keeps from the account's kind.
 */
final readonly class RemovedLine
{
    public const string WHOLESALE = 'WHOLESALE';

    public const string NOT_FOR_YOUR_ACCOUNT = 'NOT_FOR_YOUR_ACCOUNT';

    public function __construct(
        public string $variantId,
        public SaleMode $mode,
        public int $quantity,
        public string $reason,
    ) {}
}
