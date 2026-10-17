<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\SwitchCoupon;

use Carbon\CarbonImmutable;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\Coupons;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Switches a coupon off — it cannot be used — or on again, back to what its dates and uses say
 * (promotions.md §1.1, §4). A used coupon is switched off rather than deleted. Already so: nothing,
 * and nothing audited.
 */
final readonly class SwitchCouponHandler
{
    public const string PERMISSION = PromotionsPermissions::COUPON_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private Coupons $coupons,
    ) {}

    /**
     * @throws Unauthorized|CouponNotFound|InvalidPromotionAttribute
     */
    public function handle(SwitchCoupon $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->couponStore(self::PERMISSION, $command->couponId));
        $storeId = StoreId::fromString($store->id);

        $this->change->run($store, function () use ($storeId, $command): array {
            $coupon = $this->coupons->lock($storeId, $command->couponId) ?? throw new CouponNotFound;

            if ($coupon->active === $command->active) {
                return [null, []];
            }

            $this->coupons->switchTo($coupon, $command->active, CarbonImmutable::now());

            return [null, array_filter([CouponAudit::entry($command->active ? 'activated' : 'deactivated', $coupon->id, $storeId->value, ['active' => $coupon->active], ['active' => $command->active])])];
        });
    }
}
