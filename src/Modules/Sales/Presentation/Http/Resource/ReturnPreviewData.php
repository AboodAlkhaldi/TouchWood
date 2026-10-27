<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What completing a received return would refund (§4.1 P6; R2-40): the pieces, their VAT, the shipping with its VAT when it is the last piece. */
#[TypeScript]
final class ReturnPreviewData extends Data
{
    public function __construct(
        public MoneyData $goods,
        public MoneyData $vat,
        public MoneyData $shipping,
        public MoneyData $shippingVat,
        public MoneyData $total,
    ) {}
}
