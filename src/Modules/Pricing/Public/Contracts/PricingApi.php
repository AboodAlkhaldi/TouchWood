<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Contracts;

use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\KeptPartDto;
use Modules\Pricing\Public\Dto\TotalsDto;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Pricing (pricing.md §2.1). Module to module, so no permission is checked
 * here - the calling use case checks its own. Every amount is without VAT, in the store's currency.
 *
 * One price for everyone (owner, 2026-10-07): Pricing never asks who is buying. The lowest applicable
 * price wins (pricing.md §1.6); every total of an order is computed here, in one place (§1.7).
 * Refusals arrive as `Shared\Domain\Error\DomainError` with a stable `type()` key (§2.1).
 */
interface PricingApi
{
    /**
     * Each line's price in the store - the lowest that applies to it, read from the materialized
     * prices, never resolved at request time (handoff §10.1) - with the lines that have no price
     * there apart, and the subtotals over the priced ones.
     *
     * A variant appears once per sale mode: the caller merges a cart's repeated lines first, and two
     * lines of the same variant and mode are refused (`pricing.duplicate_lines`) rather than guessed at.
     * The priced lines come back in the order given; one with no price is named in `unpriced`
     * instead, so positions match the input exactly when nothing is unpriced (pricing.md §2.1).
     *
     * @param  list<CartLineDto>  $lines
     */
    public function prices(StoreId $store, array $lines): CartPricesDto;

    /**
     * The order's canonical amounts (handoff §10.2): goods total, taxable base, VAT - rounded once,
     * on the order, half up - and the order total. Pure: no database, no clock.
     *
     * May be called more than once while a quote is built: with the coupon and points first (for the
     * goods total, which free shipping binds to), then with the shipping.
     *
     * Refuses (`pricing.lines_without_price`, `pricing.amounts_invalid`) prices that hold a line with
     * no price, an amount in another currency, a negative amount, and discounts larger than the net
     * subtotal.
     */
    public function totals(CartPricesDto $prices, Money $couponDiscount, Money $pointsDiscount, Money $shipping): TotalsDto;

    /**
     * An order staff edited before it ships (pricing.md §1.11, owner 2026-10-10): the parts already on
     * it keep the prices they were sold at, and the added pieces take today's - on a wholesale line,
     * today's band for the line's new total quantity (kept and added together), on the added pieces
     * only. The result carries the order's own VAT rate, not today's, and `totals()` takes it as it
     * takes any prices. A variant and mode may appear more than once - a kept part and an added part,
     * or the parts of earlier edits - but once among the added lines (`pricing.duplicate_lines`).
     * The lines come back in the order given - the kept parts, then the added lines - so a caller
     * matches them by position; an added line with no price is named in `unpriced` instead.
     *
     * @param  list<KeptPartDto>  $kept  as the order's snapshot holds them, quantities as edited
     * @param  list<CartLineDto>  $added  the pieces added by this edit
     */
    public function pricesForEdit(StoreId $store, array $kept, array $added, int $taxRateBasisPoints): CartPricesDto;
}
