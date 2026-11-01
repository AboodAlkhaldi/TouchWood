<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** An order in the panel's list (§4.1 P1): its number, store, when, who, its three states and total. */
#[TypeScript]
final class OrderRowData extends Data
{
    public function __construct(
        public string $id,
        public string $number,
        public string $storeCode,
        public string $placedAt,
        public OrderCustomerData $customer,
        public string $status,
        public string $shipment,
        public string $payment,
        public MoneyData $total,
        public bool $shortOfStock,
        /** Fee to be set, its total before shipping (shipping.md §9.3 #8). */
        public bool $shippingFeePending = false,
    ) {}
}
