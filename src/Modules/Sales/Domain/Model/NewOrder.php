<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

use DateTimeImmutable;

/**
 * An order as it is placed (sales.md §1.3 step 7, §5 `orders`): everything snapshotted — the customer,
 * the address, the totals, the rules it was placed under — waiting for approval, unpaid, nothing
 * shipped. Amounts in its currency's smallest unit.
 */
final readonly class NewOrder
{
    /**
     * @param  array<string, mixed>  $address
     * @param  list<NewOrderLine>  $lines
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $number,
        public string $quoteId,
        public string $customerId,
        public string $audience,
        public string $locale,
        public string $currencyCode,
        public string $customerName,
        public string $customerEmail,
        public string $customerPhone,
        public array $address,
        public string $wayToPay,
        public ?string $couponId,
        public ?string $couponCode,
        public int $couponDiscount,
        public int $pointsUsed,
        public int $pointsDiscount,
        public int $grossSubtotal,
        public int $netSubtotal,
        public int $goodsTotal,
        public int $shippingFee,
        public int $shippingRuleFee,
        public int $shippingRuleFreeAbove,
        public int $taxableBase,
        public int $vat,
        public int $vatRateBasisPoints,
        public int $orderTotal,
        public ?string $giftLevelId,
        /** Whether the store was wired to a provider when the order was placed — as Inventory's hold fixed it. */
        public bool $wired,
        public DateTimeImmutable $placedAt,
        public array $lines,
    ) {}
}
