<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Model\CouponRule;
use Modules\Promotions\Domain\Model\CouponUsage;
use Modules\Promotions\Domain\Model\CouponUse;
use Modules\Promotions\Domain\Model\RuleKind;
use Modules\Promotions\Domain\Model\RuleTarget;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\DiscountKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * `Coupons` in SQL, no Eloquent model (§1.8, as Loyalty and Catalog): a coupon in two queries (its
 * row, its rules), a customer's usage in one, a use by its order in one.
 */
final readonly class DatabaseCoupons implements Coupons
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function byCode(StoreId $store, string $code): ?Coupon
    {
        return $this->toCoupon($this->coupons($store)->where('code', $code)->first());
    }

    public function byId(StoreId $store, string $couponId): ?Coupon
    {
        return Ulids::valid($couponId) ? $this->toCoupon($this->coupons($store)->where('id', strtolower($couponId))->first()) : null;
    }

    public function lock(StoreId $store, string $couponId): ?Coupon
    {
        return Ulids::valid($couponId) ? $this->toCoupon($this->coupons($store)->where('id', strtolower($couponId))->lockForUpdate()->first()) : null;
    }

    public function storeOf(string $couponId): ?StoreId
    {
        $store = Ulids::valid($couponId) ? $this->db->table('promotions.coupons')->where('id', strtolower($couponId))->value('store_id') : null;

        return $store === null ? null : StoreId::fromString((string) $store);
    }

    public function usage(StoreId $store, string $couponId, string $customerId): CouponUsage
    {
        $row = $this->db->selectOne(
            'SELECT'
            .' (SELECT count(*) FROM promotions.coupon_uses WHERE coupon_id = ? AND store_id = ? AND customer_id = ?) AS uses,'
            .' EXISTS (SELECT 1 FROM promotions.coupon_assignments WHERE coupon_id = ? AND store_id = ? AND customer_id = ?) AS assigned',
            [$couponId, $store->value, $customerId, $couponId, $store->value, $customerId],
        );

        return new CouponUsage((int) ($row->uses ?? 0), (bool) ($row->assigned ?? false));
    }

    public function useOf(string $orderId, bool $lock = false): ?CouponUse
    {
        if (! Ulids::valid($orderId)) {
            return null;
        }

        $row = $this->db->table('promotions.coupon_uses')->where('order_id', strtolower($orderId))->when($lock, fn (Builder $query) => $query->lockForUpdate())->first();

        return $row instanceof stdClass ? new CouponUse(
            (string) $row->order_id,
            (string) $row->coupon_id,
            (string) $row->store_id,
            (string) $row->customer_id,
            (int) $row->discount_minor,
            $row->last_edit_id === null ? null : (string) $row->last_edit_id,
        ) : null;
    }

    public function recordUse(Coupon $coupon, string $customerId, string $orderId, string $orderNumber, int $discountMinor, DateTimeImmutable $at): void
    {
        $this->db->table('promotions.coupon_uses')->insert([
            'coupon_id' => $coupon->id,
            'store_id' => $coupon->storeId,
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'discount_minor' => $discountMinor,
            'used_at' => $at,
        ]);

        $this->db->table('promotions.coupons')->where('id', $coupon->id)->where('store_id', $coupon->storeId)->update([
            'uses_total' => $this->db->raw('uses_total + 1'),
            'updated_at' => $at,
        ]);
    }

    public function lowerUse(string $orderId, string $editId, int $discountMinor): void
    {
        $this->db->table('promotions.coupon_uses')->where('order_id', $orderId)->update(['discount_minor' => $discountMinor, 'last_edit_id' => $editId]);
    }

    private function coupons(StoreId $store): Builder
    {
        return $this->db->table('promotions.coupons')->where('store_id', $store->value);
    }

    private function toCoupon(mixed $row): ?Coupon
    {
        if (! $row instanceof stdClass) {
            return null;
        }

        $rules = array_map(static fn (stdClass $rule): CouponRule => match (true) {
            $rule->product_id !== null => new CouponRule(RuleKind::from((string) $rule->kind), RuleTarget::Product, (string) $rule->product_id),
            $rule->category_id !== null => new CouponRule(RuleKind::from((string) $rule->kind), RuleTarget::Category, (string) $rule->category_id),
            default => new CouponRule(RuleKind::from((string) $rule->kind), RuleTarget::Brand, (string) $rule->brand_id),
        }, $this->db->table('promotions.coupon_rules')->where('coupon_id', $row->id)->orderBy('id')->get()->all());

        $currency = (string) $row->currency_code;
        $money = static fn (mixed $minor): ?Money => $minor === null ? null : Money::of((int) $minor, $currency);

        return new Coupon(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->code,
            (string) $row->name_ar,
            (string) $row->name_en,
            CouponEligibility::from((string) $row->eligibility),
            DiscountKind::from((string) $row->discount_kind),
            $row->percent === null ? null : (int) $row->percent,
            $money($row->cap_minor),
            $money($row->amount_minor),
            $currency,
            $money($row->minimum_minor),
            CarbonImmutable::parse((string) $row->starts_at),
            $row->ends_at === null ? null : CarbonImmutable::parse((string) $row->ends_at),
            $row->max_uses_per_customer === null ? null : (int) $row->max_uses_per_customer,
            $row->max_uses_total === null ? null : (int) $row->max_uses_total,
            (int) $row->uses_total,
            (bool) $row->allow_with_points,
            (bool) $row->on_reduced_lines,
            (bool) $row->for_retail,
            (bool) $row->for_wholesale,
            (bool) $row->for_public,
            (bool) $row->for_company,
            (bool) $row->active,
            array_values($rules),
        );
    }
}
