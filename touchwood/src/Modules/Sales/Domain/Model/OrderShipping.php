<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

/**
 * An order's shipping (sales.md §9.5 #2, #11, #12): the fee it pays, the rule an edit works it out from
 * again — the place's price, and the free-above amount that applied with whose it was (`CARRIER`,
 * `STORE`; none: never free) — whether the fee is **to be set** by staff (a place no carrier served:
 * the fee 0, no carrier), and the carrier ordered with, its names as they were. Amounts in the order's
 * currency's smallest unit.
 */
final readonly class OrderShipping
{
    public function __construct(
        public int $fee,
        public int $ruleFee,
        public ?int $ruleFreeAbove,
        public ?string $ruleFreeKind,
        public bool $pending,
        public ?string $carrierId,
        public ?string $carrierAr,
        public ?string $carrierEn,
    ) {}

    /** The fee by the order's own rule for this goods total: free from the amount that applied (§1.7 step 5). */
    public function feeFor(int $goodsTotal): int
    {
        if ($this->pending) {
            return 0;
        }

        return $this->ruleFreeAbove !== null && $goodsTotal >= $this->ruleFreeAbove ? 0 : $this->ruleFee;
    }

    /** The same rule and carrier, paying this fee. */
    public function paying(int $fee): self
    {
        return new self($fee, $this->ruleFee, $this->ruleFreeAbove, $this->ruleFreeKind, $this->pending, $this->carrierId, $this->carrierAr, $this->carrierEn);
    }
}
