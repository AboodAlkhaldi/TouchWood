<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ViewMyCoupons;

use Carbon\CarbonImmutable;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Application\Query\MyCouponRow;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Public\Enums\CouponState;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * My coupons in a store (promotions.md §3, "My coupons"): `promotions.coupon.view_own`, about the
 * signed-in customer only — the coupons assigned to me there that I can use now: switched on, started,
 * not ended, uses left in all and for me. A store switched off has none to use. Soonest to end first.
 */
final readonly class ViewMyCouponsHandler
{
    public const string PERMISSION = PromotionsPermissions::VIEW_OWN;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PlatformApi $platform,
        private MarketingQueries $queries,
    ) {}

    /**
     * @return list<MyCouponRow>
     *
     * @throws Unauthorized|InvalidPromotionAttribute
     */
    public function handle(ViewMyCoupons $query): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $store = Inputs::store($query->storeId);

        if (! ($this->platform->store($store)->isActive ?? false)) {
            return [];
        }

        $rows = [];

        foreach ($this->queries->assignedTo($store, $actor->id, CarbonImmutable::now()) as $given) {
            $coupon = $given['row'];
            $left = $given['maxUsesPerCustomer'] === null ? null : $given['maxUsesPerCustomer'] - $given['usesByMe'];

            if ($coupon->state !== CouponState::Active || ($left !== null && $left <= 0)) {
                continue;
            }

            $rows[] = new MyCouponRow(
                $coupon->code, $coupon->nameAr, $coupon->nameEn, $coupon->discountKind, $coupon->percent,
                $given['cap'] === null ? null : Money::of($given['cap'], $coupon->currencyCode),
                $coupon->amount,
                $given['minimum'] === null ? null : Money::of($given['minimum'], $coupon->currencyCode),
                $coupon->endsAt,
                $left,
            );
        }

        return $rows;
    }
}
