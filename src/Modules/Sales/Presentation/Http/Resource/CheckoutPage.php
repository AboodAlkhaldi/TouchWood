<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * S5 - checkout (sales.md §4.1), one page over the quote `StartCheckout` worked out for what is chosen on it:
 * the address, the points, the way to pay. No quote while the account has no address it can use here.
 * Every amount the server's; a summary line with nothing in it is left for the page not to show (R2-15).
 */
#[TypeScript]
final class CheckoutPage extends Data
{
    /**
     * @param  list<CheckoutAddressData>  $addresses
     * @param  list<string>  $ways
     */
    public function __construct(
        public ?string $quoteId,
        public array $addresses,
        public ?string $addressId,
        public string $audience,
        public ?string $couponCode,
        public ?string $couponName,
        public ?string $couponRefusal,
        public bool $usePoints,
        public ?int $pointsAsked,
        public int $pointsUsed,
        public int $pointsMostUsable,
        /** The store redeems any number up to the most (PARTIAL), or all or none (ALL_OR_NOTHING); null before points are asked. */
        public ?string $pointsMode,
        public ?string $pointsRefusal,
        public array $ways,
        /** The way chosen; null while a company has not chosen one (R2-14). */
        public ?string $way,
        public ?BankData $bank,
        public ?MoneyData $subtotal,
        public ?MoneyData $savings,
        public ?MoneyData $coupon,
        public ?MoneyData $points,
        public ?MoneyData $shipping,
        public ?MoneyData $freeAbove,
        public ?MoneyData $vat,
        public int $vatRateBasisPoints,
        public ?MoneyData $total,
        /** The free gift's product, when the order reaches a level. */
        public ?string $gift,
        public ?MoneyData $giftPrice,
        public int $earns,
    ) {}
}
