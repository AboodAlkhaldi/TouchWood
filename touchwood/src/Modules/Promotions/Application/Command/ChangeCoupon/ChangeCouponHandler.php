<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ChangeCoupon;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\Coupon\RuleTargets;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\CouponInUse;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\CouponRule;
use Modules\Promotions\Domain\Repository\Assignments;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\SegmentAudience;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Changes a coupon's terms and lists (promotions.md §1.1, §3) — for every later use; a placed order
 * keeps the discount it had. Its code and store never change. The total of uses set below the uses
 * already made is refused; set to exactly them, it ends the coupon. The coupon's row is locked first,
 * so an order being placed never reads it half changed.
 *
 * **Only the list entries this change adds are checked against Catalog** (owner, 2026-10-17): one
 * already on the coupon stays though its product was archived or its category or brand switched off
 * since — it simply matches no cart line — so a rename is never blocked by an old entry.
 */
final readonly class ChangeCouponHandler
{
    public const string PERMISSION = PromotionsPermissions::COUPON_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private Coupons $coupons,
        private RuleTargets $targets,
        private Assignments $assignments,
    ) {}

    /**
     * @throws Unauthorized|CouponNotFound|InvalidPromotionAttribute|CouponInUse
     */
    public function handle(ChangeCoupon $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->couponStore(self::PERMISSION, $command->couponId));
        $terms = $command->fields->terms($store->currencyCode);
        $storeId = StoreId::fromString($store->id);

        try {
            $this->change->run($store, function () use ($storeId, $terms, $command): array {
                $coupon = $this->coupons->lock($storeId, $command->couponId) ?? throw new CouponNotFound;
                $kept = array_map(static fn (CouponRule $rule): string => "{$rule->kind->value} {$rule->target->value} {$rule->targetId}", $coupon->rules);
                $this->targets->check(array_values(array_filter(
                    $terms->rules,
                    static fn (CouponRule $rule): bool => ! in_array("{$rule->kind->value} {$rule->target->value} {$rule->targetId}", $kept, true),
                )));

                // Below its uses only when lowered now: a coupon a paid hold took past its limit ("100 of 99",
                // §9.6) may still be edited with the limit it has.
                if ($terms->maxUsesTotal !== null && $terms->maxUsesTotal < $coupon->usesTotal && $terms->maxUsesTotal !== $coupon->maxUsesTotal) {
                    throw new CouponInUse;
                }

                // Given to customers, it stays for assigned customers only: no silent loss or return of the
                // coupon for them (owner, 2026-10-18, answer 25) — they are removed first, or a new coupon made.
                if ($coupon->eligibility === CouponEligibility::Assigned && $terms->eligibility !== CouponEligibility::Assigned && $this->assignments->any($coupon)) {
                    throw new InvalidPromotionAttribute('eligibility', 'assigned customers have it; remove them first');
                }

                // Assigned from an individuals' (or companies') segment, it may not then leave them out (§1.1).
                foreach ($this->assignments->segmentAudiences($coupon) as $audience) {
                    if ($audience === SegmentAudience::Individual ? ! $terms->forPublic : ! $terms->forCompany) {
                        throw new InvalidPromotionAttribute('audience', "it was assigned from a segment of {$audience->value} customers");
                    }
                }

                $entry = CouponAudit::entry('updated', $coupon->id, $storeId->value, $coupon->terms()->snapshot(), $terms->snapshot());

                if ($entry !== null) {
                    $this->coupons->change($coupon, $terms, CarbonImmutable::now());
                }

                return [null, array_filter([$entry])];
            });
        } catch (QueryException $failed) {
            throw RuleTargets::removedMeanwhile($failed);
        }
    }
}
