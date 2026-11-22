<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\BuyAgain;

use Modules\Sales\Public\Enums\CartLineProblem;

/** A line of the order Buy Again could not put back in the cart, and why — its names as it was sold. */
final readonly class BuyAgainSkipped
{
    public function __construct(
        public string $variantId,
        public string $nameAr,
        public string $nameEn,
        public string $mode,
        public int $quantity,
        public CartLineProblem $reason,
    ) {}
}
