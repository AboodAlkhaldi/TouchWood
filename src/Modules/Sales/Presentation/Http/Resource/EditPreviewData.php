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
    ) {}
}
