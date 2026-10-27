<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** P6 - a return in the panel (§4.1): its order, the customer's reason, the lines, the photos, its moments and refund, and the reader's jobs. */
#[TypeScript]
final class ReturnPage extends Data
{
    /**
     * @param  list<ReturnLineData>  $lines
     * @param  list<ReturnPhotoData>  $photos
     */
    public function __construct(
        public string $id,
        public string $orderId,
        public string $orderNumber,
        public SalesStoreData $store,
        public OrderCustomerData $customer,
        public string $status,
        public string $reason,
        public ?string $reasonOther,
        public ?string $staffReason,
        public string $requestedAt,
        public ?string $decidedBy,
        public ?string $decidedAt,
        public ?string $withdrawnAt,
        public ?string $receivedAt,
        public ?string $closedAt,
        public ?string $completedAt,
        public ?MoneyData $refundDue,
        public bool $shippingRefunded,
        public array $lines,
        public array $photos,
        public bool $mayManage,
        public bool $mayRecordMoney,
        public ?string $refundDueDecimal,
        public string $storeTimezone,
        public int $exponent,
    ) {}
}
