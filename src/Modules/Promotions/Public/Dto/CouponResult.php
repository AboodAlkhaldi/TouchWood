<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Domain\ValueObject\Money;

/**
 * What a coupon takes off a cart, or why it is refused (promotions.md §2.2). Taken: the coupon's id and
 * names, the total, and **one share per line of the prices passed, in their order** — 0 for a line it
 * does not cover — adding up to the total exactly; whether the order may also use points. Refused: the
 * reason, and the coupon's id and names when the code named one.
 */
final readonly class CouponResult
{
    /**
     * @param  list<Money>  $shares
     */
    private function __construct(
        public ?string $couponId,
        public ?string $nameAr,
        public ?string $nameEn,
        public ?Money $discount,
        public array $shares,
        public bool $allowWithPoints,
        public ?CouponRefusal $refusal,
    ) {}

    /**
     * @param  list<Money>  $shares
     */
    public static function taken(string $couponId, string $nameAr, string $nameEn, Money $discount, array $shares, bool $allowWithPoints): self
    {
        return new self($couponId, $nameAr, $nameEn, $discount, $shares, $allowWithPoints, null);
    }

    public static function refused(CouponRefusal $refusal, ?string $couponId = null, ?string $nameAr = null, ?string $nameEn = null): self
    {
        return new self($couponId, $nameAr, $nameEn, null, [], false, $refusal);
    }

    public function isTaken(): bool
    {
        return $this->refusal === null;
    }
}
