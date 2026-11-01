<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use DateTimeImmutable;
use Modules\Sales\Application\Query\OrderShippingView;
use Modules\Sales\Application\Query\Own\OrderLineView;
use Modules\Sales\Application\Query\Own\OwnReturnRow;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Modules\Sales\Public\Enums\PaymentStatus;
use Modules\Sales\Public\Enums\WayToPay;
use Modules\Shipping\Public\Dto\BoxSuggestionDto;
use Shared\Domain\ValueObject\Money;

/**
 * An order as staff see it (sales.md §3 ViewOrder): the four machines; the customer by name, and the contact
 * the order was placed with (to call them); its lines and totals; the decision, the cancellation, the
 * shipment and the money recorded; the history, the edits, the ticks, the returns and the documents. Staff
 * names in place of ids; staff's own words where staff read them.
 */
final readonly class StaffOrderView
{
    /**
     * @param  array<string, mixed>  $address
     * @param  list<OrderLineView>  $lines
     * @param  list<MoneyRow>  $money
     * @param  list<HistoryRow>  $history
     * @param  list<EditRow>  $edits
     * @param  list<array{variantId: string, by: ?string, at: DateTimeImmutable}>  $ticks
     * @param  list<OwnReturnRow>  $returns
     * @param  list<array{mediaId: string, position: int, uploadedAt: DateTimeImmutable}>  $transferDocuments
     */
    public function __construct(
        public string $orderId,
        public string $number,
        public string $storeId,
        public DateTimeImmutable $placedAt,
        public CustomerName $customer,
        public string $contactName,
        public ?string $contactEmail,
        public string $contactPhone,
        public string $audience,
        public string $status,
        public string $shipment,
        public PaymentStatus $payment,
        public CustomerOrderStatus $customerStatus,
        public WayToPay $wayToPay,
        public bool $mayShipUnpaid,
        public ?DateTimeImmutable $shortOfStockAt,
        public array $lines,
        public Money $grossSubtotal,
        public Money $netSubtotal,
        public ?string $couponCode,
        public Money $couponDiscount,
        public int $pointsUsed,
        public Money $pointsDiscount,
        public Money $goodsTotal,
        public Money $shipping,
        public Money $vat,
        public Money $total,
        public Money $due,
        public Money $paid,
        public Money $refunded,
        public array $address,
        public ?string $decidedBy,
        public ?DateTimeImmutable $decidedAt,
        public ?string $decisionReason,
        public ?string $cancelReason,
        public ?string $cancelOther,
        public ?string $cancelledBy,
        public ?DateTimeImmutable $cancelledAt,
        public ?string $carrier,
        public ?string $trackingNumber,
        public ?DateTimeImmutable $shippedAt,
        public ?DateTimeImmutable $deliveredAt,
        public ?DateTimeImmutable $returnDeadline,
        public ?DateTimeImmutable $cameBackAt,
        public array $money,
        public array $history,
        public array $edits,
        public array $ticks,
        public array $returns,
        public array $transferDocuments,
        public int $exponent,
        /** Placed in a store wired to a provider (§1.5): its sizes may be ticked "reduced in the provider". */
        public bool $wired = false,
        /** @var list<string> the sizes that may be ticked: a wired order's sizes whose stock counted, and its gift */
        public array $tickable = [],
        /** The carriers ordered and shipped with, the tracking link, the fee still to be set (§9.5 #11). */
        public ?OrderShippingView $shippingDetail = null,
        /** The box Shipping suggests, or pack by hand with why; null with no boxes (gap 5.1). */
        public ?BoxSuggestionDto $box = null,
    ) {}
}
