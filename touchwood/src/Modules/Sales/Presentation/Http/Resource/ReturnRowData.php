<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A return in the panel's list (sales.md §4.1 P5): its order, store, customer, state, when asked, its refund once completed. */
#[TypeScript]
final class ReturnRowData extends Data
{
    public function __construct(
        public string $id,
        public string $orderId,
        public string $orderNumber,
        public string $storeCode,
        public OrderCustomerData $customer,
        public string $status,
        public string $requestedAt,
        public ?MoneyData $refundDue,
    ) {}
}
