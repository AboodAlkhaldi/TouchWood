<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * S8 - an order of the customer's (sales.md §4.1): the status they see and its timeline, the payment in plain words
 * (never "paid x of y"), the lines and totals, the address and way to pay, the shipment, Cancel, the returns, Buy Again.
 * Every amount the server's; a total line with nothing in it is null.
 */
#[TypeScript]
final class OwnOrderPage extends Data
{
    /**
     * @param  list<OwnLineData>  $lines
     * @param  list<OwnReturnRowData>  $returns
     */
    public function __construct(
        public string $number,
        public string $placedAt,
        public string $status,
        public string $payment,
        public MoneyData $owed,
        public MoneyData $refundDue,
        public array $lines,
        public MoneyData $subtotal,
        public ?MoneyData $savings,
        public ?string $couponCode,
        public ?MoneyData $coupon,
        public int $pointsUsed,
        public ?MoneyData $points,
        public MoneyData $shipping,
        public MoneyData $vat,
        public MoneyData $total,
        public string $recipientName,
        public string $contactPhone,
        public string $address,
        public string $wayToPay,
        public ?BankData $bank,
        public int $receipts,
        public bool $mayUpload,
        public string $shipment,
        public ?string $carrier,
        public ?string $trackingNumber,
        public ?string $shippedAt,
        public ?string $deliveredAt,
        public ?string $returnDeadline,
        public array $returns,
        public bool $mayCancel,
        public bool $mayReturn,
        public string $storeTimezone,
    ) {}
}
