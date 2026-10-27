<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An order on the staff customer page (sales.md §3.2): `StaffOrderRow` without the customer, its moment
 * in UTC with milliseconds; `status` and `shipment` staff's machines, `payment` and `customerStatus` the
 * enums' values.
 */
#[TypeScript]
final class CustomerOrderData extends Data
{
    public function __construct(
        public string $orderId,
        public string $number,
        public string $storeId,
        public string $placedAt,
        public string $status,
        public string $shipment,
        public string $payment,
        public string $customerStatus,
        public MoneyData $total,
        public bool $shortOfStock,
        public bool $mayShipUnpaid,
    ) {}
}
