<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** P3 - an order's packing slip (§4.1): its contact, address, lines with their codes, and totals. */
#[TypeScript]
final class PrintPage extends Data
{
    /**
     * @param  list<OrderLineData>  $lines
     */
    public function __construct(
        public string $id,
        public string $number,
        public string $storeName,
        public string $placedAt,
        public string $contactName,
        public string $contactPhone,
        public string $address,
        public array $lines,
        public MoneyData $grossSubtotal,
        public MoneyData $netSubtotal,
        public MoneyData $couponDiscount,
        public MoneyData $pointsDiscount,
        public MoneyData $goodsTotal,
        public MoneyData $shipping,
        public MoneyData $vat,
        public int $vatRateBasisPoints,
        public MoneyData $total,
        public string $wayToPay,
        public string $storeTimezone,
    ) {}
}
