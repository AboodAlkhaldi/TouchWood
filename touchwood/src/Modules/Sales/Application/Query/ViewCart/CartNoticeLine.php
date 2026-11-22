<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

use Modules\Sales\Public\Enums\CartNoticeReason;

/**
 * A line that left the cart, and why (sales.md §1.1: the notice of a sign-in or a merge) — named, so the shopper
 * reads which product it was.
 */
final readonly class CartNoticeLine
{
    /**
     * @param  list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>  $values
     */
    public function __construct(
        public string $variantId,
        public string $name,
        public string $code,
        public array $values,
        public string $mode,
        public int $quantity,
        public CartNoticeReason $reason,
    ) {}
}
