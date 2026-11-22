<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An edit worked out before it is confirmed (§4.1 P4): every total after it, and the money in plain words (R2-37) -
 * `money` MORE or LESS (the customer will owe `difference` more or less than before), REFUND (a refund of `difference`
 * will be due) or NONE.
 */
#[TypeScript]
final class EditPreviewData extends Data
{
    /**
     * @param  list<EditGiftData>  $gifts
     * @param  list<CheckoutCarrierData>  $carriers  at an address at another place: the carriers serving it with today's fees, cheapest first (gap 6.1)
     */
    public function __construct(
        public MoneyData $subtotal,
        public ?MoneyData $coupon,
        public ?MoneyData $points,
        public MoneyData $shipping,
        public MoneyData $vat,
        public MoneyData $total,
        public MoneyData $before,
        public string $money,
        public MoneyData $difference,
        public array $gifts,
        public ?string $giftLevelId,
        /** The address moved to another place: its shipping was quoted again (gap 6.1). */
        public bool $placeChanged = false,
        public array $carriers = [],
        /** The carrier the edit leaves on the order, and whether its fee is then to be set. */
        public ?string $carrierId = null,
        public bool $shippingPending = false,
    ) {}
}
