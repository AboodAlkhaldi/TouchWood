<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

use DateTimeImmutable;

/**
 * An order's machines as they stand (sales.md §1.4): what a change asks before it is made. Amounts in
 * the order's currency's smallest unit.
 */
final readonly class OrderState
{
    public const string PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    public const string CANCELLED = 'CANCELLED';

    public const string NOT_SHIPPED = 'NOT_SHIPPED';

    public const string SHIPPED = 'SHIPPED';

    public const string DELIVERED = 'DELIVERED';

    public const string CAME_BACK = 'CAME_BACK';

    public const string COMPLETED = 'COMPLETED';

    public const string UNDELIVERED = 'UNDELIVERED';

    public function isShipped(): bool
    {
        return $this->shipmentStatus === self::SHIPPED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function __construct(
        public string $id,
        public string $storeId,
        public string $number,
        public string $customerId,
        public string $status,
        public string $shipmentStatus,
        public int $due,
        public int $paid,
        public int $refunded,
        public ?DateTimeImmutable $shortOfStockAt,
        public string $currencyCode,
        public bool $mayShipUnpaid,
        public string $wayToPay,
        public int $goodsTotal,
        /** Wired to a provider when placed (§1.5): ticks and a parcel that came back go by it. */
        public bool $wired,
    ) {}

    public function isWaiting(): bool
    {
        return $this->status === self::PENDING_APPROVAL;
    }

    /** Waiting or approved, and nothing shipped: it may still be cancelled (§1.6). */
    public function isCancellable(): bool
    {
        return in_array($this->status, [self::PENDING_APPROVAL, self::APPROVED], true) && $this->shipmentStatus === self::NOT_SHIPPED;
    }

    /** What the order holds: paid less refunded (§1.4). */
    public function held(): int
    {
        return $this->paid - $this->refunded;
    }
}
