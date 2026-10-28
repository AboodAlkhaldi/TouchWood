<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\AssignCoupon;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\Assignments;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\SegmentAudience;
use Modules\Promotions\Public\Events\CouponAssigned;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * Assigns a coupon to a segment (promotions.md §1.4): **writes the segment's members of that moment**
 * as the coupon's customers, and they stay — a customer who leaves the segment later keeps it.
 * Assigning again adds the new members only; nobody is removed. Only an `ASSIGNED` coupon is assigned
 * (owner, 2026-10-18, answer 24); the segment must be the coupon's own store's, of an audience the
 * coupon allows (§1.1). The customers see it in their account centre at once; each one new to it gets
 * `CouponAssigned` through the outbox, for Ops' message (§6; ops.md §1.5 C15). Audited.
 */
final readonly class AssignCouponHandler
{
    public const string PERMISSION = PromotionsPermissions::COUPON_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private Coupons $coupons,
        private Segments $segments,
        private Assignments $assignments,
        private Outbox $outbox,
    ) {}

    /**
     * @throws Unauthorized|CouponNotFound|InvalidPromotionAttribute
     */
    public function handle(AssignCoupon $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->couponStore(self::PERMISSION, $command->couponId));
        $storeId = StoreId::fromString($store->id);

        $this->change->run($store, function () use ($storeId, $command): array {
            $coupon = $this->coupons->lock($storeId, $command->couponId) ?? throw new CouponNotFound;

            if ($coupon->eligibility !== CouponEligibility::Assigned) {
                throw new InvalidPromotionAttribute('eligibility', 'only a coupon for assigned customers is assigned');
            }

            // A segment of another store, or none, is not one this coupon can take (§8).
            $segment = $this->segments->lock($storeId, $command->segmentId) ?? throw new InvalidPromotionAttribute('segment', 'not a segment of the coupon\'s store');

            if ($segment->audience === SegmentAudience::Individual ? ! $coupon->forPublic : ! $coupon->forCompany) {
                throw new InvalidPromotionAttribute('audience', 'the coupon is not for this segment\'s customers');
            }

            $now = CarbonImmutable::now();
            $added = $this->assignments->assign($coupon, $this->segments->memberIds($segment->id), $segment->id, $now);

            foreach ($added as $customerId) {
                $this->outbox->record(new CouponAssigned((string) Str::uuid(), $coupon->id, $coupon->storeId, $customerId, $now));
            }

            return [null, $added === [] ? [] : array_filter([CouponAudit::entry('assigned', $coupon->id, $storeId->value, [], ['segment' => $segment->id, 'customers' => count($added)])])];
        });
    }
}
