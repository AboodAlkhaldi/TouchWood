<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

use DateTimeImmutable;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Modules\Sales\Public\Enums\PaymentStatus;
use Shared\Domain\ValueObject\Money;

/**
 * The customer's own order (sales.md §3 ViewOwnOrder): its lines as sold, its totals, the one status they see
 * and the payment's, how it ships, the address it goes to, its returns and transfer documents, and what they
 * may still do — cancel, ask a return, upload a document. Known by its number.
 */
final readonly class OwnOrderView
{
    /**
     * @param  array<string, mixed>  $address  as placed (or as staff last set it): label, recipient name, phone, fields, the map point, formatted
     * @param  list<OrderLineView>  $lines
     * @param  list<OwnReturnRow>  $returns
     */
    public function __construct(
        public string $number,
        public string $storeId,
        public DateTimeImmutable $placedAt,
        public CustomerOrderStatus $status,
        public PaymentStatus $payment,
        public string $wayToPay,
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
        public string $shipment,
        public ?string $carrier,
        public ?string $trackingNumber,
        public ?DateTimeImmutable $shippedAt,
        public ?DateTimeImmutable $deliveredAt,
        public ?DateTimeImmutable $returnDeadline,
        public array $address,
        public array $returns,
        public int $transferDocuments,
        public bool $mayCancel,
        public bool $mayReturn,
        public bool $mayUploadTransferDocument,
    ) {}
}
