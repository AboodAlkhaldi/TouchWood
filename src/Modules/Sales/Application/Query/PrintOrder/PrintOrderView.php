<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\PrintOrder;

use DateTimeImmutable;
use Modules\Sales\Application\Query\Own\OrderLineView;
use Modules\Sales\Public\Enums\WayToPay;
use Modules\Shipping\Public\Dto\BoxSuggestionDto;
use Shared\Domain\ValueObject\Money;

/**
 * What a printed order carries (sales.md §3 PrintOrder): the store, the number, when placed, the contact and the
 * address it goes to, the lines as sold, the totals and the way to pay — a packing slip and an invoice's
 * facts, its layout the screen's.
 */
final readonly class PrintOrderView
{
    /**
     * @param  array<string, mixed>  $address
     * @param  list<OrderLineView>  $lines
     */
    public function __construct(
        public string $storeId,
        public string $number,
        public DateTimeImmutable $placedAt,
        public string $contactName,
        public string $contactPhone,
        public array $address,
        public array $lines,
        public Money $grossSubtotal,
        public Money $netSubtotal,
        public Money $couponDiscount,
        public Money $pointsDiscount,
        public Money $goodsTotal,
        public Money $shipping,
        public Money $vat,
        public int $vatRateBasisPoints,
        public Money $total,
        public WayToPay $wayToPay,
        public int $exponent,
        /** The box Shipping suggests, or pack by hand with why; null with no boxes (gap 5.1). */
        public ?BoxSuggestionDto $box = null,
    ) {}
}
