<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A card's price for its viewer (amendment 35), without VAT, each part written by the server in the
 * store's currency: the retail price `now` and `before` (only while now is lower), `from` the lowest
 * retail price among its sizes (only when they differ), and `wholesaleFrom` — a company's and a guest's
 * only — the least one piece costs on a wholesale line. A part with no price is null.
 */
#[TypeScript]
final class ShopCardPriceData extends Data
{
    public function __construct(
        public ?MoneyData $now,
        public ?MoneyData $before,
        public ?MoneyData $from,
        public ?MoneyData $wholesaleFrom,
    ) {}
}
