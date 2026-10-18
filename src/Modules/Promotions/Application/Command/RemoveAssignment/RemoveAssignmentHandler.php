<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\RemoveAssignment;

use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\CouponInUse;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\Assignments;
use Modules\Promotions\Domain\Repository\Coupons;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Takes an assigned coupon back from one customer, while they have not used it (promotions.md §1.4,
 * my assumption accepted with the spec); one already used is `CouponInUse` — a use never comes back.
 * Under the coupon's lock, so no order uses it meanwhile. Audited.
 */
final readonly class RemoveAssignmentHandler
{
    public const string PERMISSION = PromotionsPermissions::COUPON_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private Coupons $coupons,
        private Assignments $assignments,
    ) {}

    /**
     * @throws Unauthorized|CouponNotFound|InvalidPromotionAttribute|CouponInUse
     */
    public function handle(RemoveAssignment $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->couponStore(self::PERMISSION, $command->couponId));
        $storeId = StoreId::fromString($store->id);
        $customerId = Inputs::id($command->customerId, 'customer');

        $this->change->run($store, function () use ($storeId, $command, $customerId): array {
            $coupon = $this->coupons->lock($storeId, $command->couponId) ?? throw new CouponNotFound;

            $usage = $this->coupons->usage($storeId, $coupon->id, $customerId);

            // Never given to them: nothing to take back, whatever they did with it.
            if (! $usage->assigned) {
                return [null, []];
            }

            if ($usage->usesByCustomer > 0) {
                throw new CouponInUse;
            }

            $this->assignments->remove($coupon, $customerId);

            return [null, array_filter([CouponAudit::entry('assignment_removed', $coupon->id, $storeId->value, ['customer' => $customerId], [])])];
        });
    }
}
