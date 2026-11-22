<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\DeleteCoupon;

use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\CouponInUse;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\Coupons;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Deletes a coupon never used, its lists and assignments with it, in one transaction (promotions.md
 * §1.1). **A coupon used once is never deleted** — switched off instead (`CouponInUse`); a use never
 * comes back. Under the coupon's lock, so no order uses it meanwhile.
 */
final readonly class DeleteCouponHandler
{
    public const string PERMISSION = PromotionsPermissions::COUPON_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private Coupons $coupons,
    ) {}

    /**
     * @throws Unauthorized|CouponNotFound|InvalidPromotionAttribute|CouponInUse
     */
    public function handle(DeleteCoupon $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->couponStore(self::PERMISSION, $command->couponId));
        $storeId = StoreId::fromString($store->id);

        $this->change->run($store, function () use ($storeId, $command): array {
            $coupon = $this->coupons->lock($storeId, $command->couponId) ?? throw new CouponNotFound;

            if ($this->coupons->wasUsed($coupon)) {
                throw new CouponInUse;
            }

            $this->coupons->delete($coupon);

            return [null, array_filter([CouponAudit::entry('deleted', $coupon->id, $storeId->value, ['code' => $coupon->code, 'active' => $coupon->active, ...$coupon->terms()->snapshot()], [])])];
        });
    }
}
