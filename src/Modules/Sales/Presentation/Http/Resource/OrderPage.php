<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** P2 - an order in the panel (§4.1): everything of §3.2's staff view, money written by the server, and the reader's jobs. */
#[TypeScript]
final class OrderPage extends Data
{
    /**
     * @param  list<OrderLineData>  $lines
     * @param  list<MoneyRecordData>  $money
     * @param  list<OrderHistoryData>  $history
     * @param  list<OrderEditData>  $edits
     * @param  list<OrderTickData>  $ticks
     * @param  list<string>  $tickable
     * @param  list<OrderCarrierData>  $carriers
     * @param  list<OrderReturnData>  $returns
     * @param  list<OrderFileData>  $transferDocuments
     */
    public function __construct(
        public string $id,
        public string $number,
        public SalesStoreData $store,
        public string $placedAt,
        public OrderCustomerData $customer,
        public string $contactName,
        public ?string $contactEmail,
        public string $contactPhone,
        public string $audience,
        public string $status,
        public string $shipment,
        public string $payment,
        public string $customerStatus,
        public string $wayToPay,
        public bool $mayShipUnpaid,
        public ?string $shortOfStockAt,
        public array $lines,
        public MoneyData $grossSubtotal,
        public MoneyData $netSubtotal,
        public ?string $couponCode,
        public MoneyData $couponDiscount,
        public int $pointsUsed,
        public MoneyData $pointsDiscount,
        public MoneyData $goodsTotal,
        public MoneyData $shipping,
        public MoneyData $vat,
        public MoneyData $total,
        public MoneyData $due,
        public MoneyData $paid,
        public MoneyData $refunded,
        public MoneyData $owed,
        public MoneyData $refundDue,
        public MoneyData $held,
        public MoneyData $savings,
        public string $recipientName,
        public string $address,
        public ?string $decidedBy,
        public ?string $decidedAt,
        public ?string $decisionReason,
        public ?string $cancelReason,
        public ?string $cancelOther,
        public ?string $cancelledBy,
        public ?string $cancelledAt,
        public ?string $carrier,
        public ?string $trackingNumber,
        public ?string $shippedAt,
        public ?string $deliveredAt,
        public ?string $returnDeadline,
        public ?string $cameBackAt,
        public array $money,
        public array $history,
        public array $edits,
        public array $ticks,
        public bool $wired,
        public array $tickable,
        public array $returns,
        public array $transferDocuments,
        public OrderActionsData $actions,
        public string $storeTimezone,
        public int $exponent,
        /** What is owed in its decimals ("248.00"), sent back with Record Payment: refused once it is no longer so. */
        public string $owedDecimal,
        /** The store's carriers for Ship… and its correction (gap 6.3), the order's country first; none for a reader who does not ship. */
        public array $carriers,
        /** The carrier it shipped with — one of the store's; null for "Other carrier" or not shipped. */
        public ?string $carrierId = null,
        /** The full tracking link stored when it shipped; null: no Track. */
        public ?string $trackingLink = null,
        /** The carrier ordered with, in the reader's language (§9.5 #11). */
        public ?string $orderedCarrier = null,
        /** No carrier served the place: staff set the fee before it ships (gap 6.2). */
        public bool $shippingFeePending = false,
        /** The box suggested, or pack by hand with why; null while the store has no boxes (gap 5.1). */
        public ?OrderBoxData $box = null,
        /** The carrier ordered with: picked first in Ship… (sales.md §9.7 E12); none for a fee staff set. */
        public ?string $orderedCarrierId = null,
    ) {}
}
