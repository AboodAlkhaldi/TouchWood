<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Promotions\Application\Query\CouponRow;
use Modules\Promotions\Application\Query\CouponStates;
use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\CouponState;
use Modules\Promotions\Public\Enums\DiscountKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/** `MarketingQueries` in SQL, each page one query (§1.8, handoff §5.4). */
final readonly class DatabaseMarketingQueries implements MarketingQueries
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function coupons(StoreId $store, ?CouponState $state, ?string $codeStart, ?DateTimeImmutable $beforeAt, ?string $beforeId, int $limit, DateTimeImmutable $now): array
    {
        $at = Moment::of($now);
        $ended = fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNotNull('ends_at')->where('ends_at', '<=', $at))
            ->orWhere(fn (Builder $q) => $q->whereNotNull('max_uses_total')->whereColumn('uses_total', '>=', 'max_uses_total'));
        $notEnded = fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->where(fn (Builder $q) => $q->whereNull('max_uses_total')->orWhereColumn('uses_total', '<', 'max_uses_total'));

        $query = $this->db->table('promotions.coupons')->where('store_id', $store->value);

        match ($state) {
            CouponState::Ended => $query->where($ended),
            CouponState::Inactive => $query->where($notEnded)->where('active', false),
            CouponState::Scheduled => $query->where($notEnded)->where('active', true)->where('starts_at', '>', $at),
            CouponState::Active => $query->where($notEnded)->where('active', true)->where('starts_at', '<=', $at),
            null => null,
        };

        if ($codeStart !== null) {
            // Codes are Latin letters, digits and hyphens, stored in capitals (§1.1): anything else can
            // start none — and never reaches LIKE as a wildcard (the handler reads it as typed first).
            if (preg_match('/\A[A-Z0-9-]{1,30}\z/', $codeStart) !== 1) {
                return [];
            }

            $query->where('code', 'like', $codeStart.'%');
        }

        if ($beforeAt !== null && $beforeId !== null) {
            $query->whereRaw('(created_at, id) < (?, ?)', [Moment::of($beforeAt), strtolower($beforeId)]);
        }

        return array_values(array_map(
            static fn (stdClass $row): CouponRow => self::toRow($row, $now),
            $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->all(),
        ));
    }

    public function couponRow(StoreId $store, string $couponId, DateTimeImmutable $now): ?CouponRow
    {
        $row = $this->db->table('promotions.coupons')->where('store_id', $store->value)->where('id', strtolower($couponId))->first();

        return $row instanceof stdClass ? self::toRow($row, $now) : null;
    }

    public function assignedCount(string $couponId): int
    {
        return $this->db->table('promotions.coupon_assignments')->where('coupon_id', $couponId)->count();
    }

    public function uses(string $couponId, ?DateTimeImmutable $beforeAt, ?int $beforeId, int $limit): array
    {
        $query = $this->db->table('promotions.coupon_uses')->where('coupon_id', $couponId);

        if ($beforeAt !== null && $beforeId !== null) {
            $query->whereRaw('(used_at, id) < (?, ?)', [Moment::of($beforeAt), $beforeId]);
        }

        return array_values(array_map(static fn (stdClass $row): array => [
            'id' => (int) $row->id,
            'orderNumber' => (string) $row->order_number,
            'customerId' => (string) $row->customer_id,
            'discountMinor' => (int) $row->discount_minor,
            'usedAt' => CarbonImmutable::parse((string) $row->used_at),
        ], $query->orderByDesc('used_at')->orderByDesc('id')->limit($limit)->get(['id', 'order_number', 'customer_id', 'discount_minor', 'used_at'])->all()));
    }

    public function assignments(string $couponId, ?DateTimeImmutable $beforeAt, ?string $beforeCustomerId, int $limit): array
    {
        $query = $this->db->table('promotions.coupon_assignments as a')
            ->leftJoin('promotions.segments as s', 's.id', '=', 'a.segment_id')
            ->where('a.coupon_id', $couponId)
            ->select(['a.customer_id', 'a.assigned_at', 'a.segment_id', 's.name_ar', 's.name_en'])
            ->selectSub(fn (Builder $uses) => $uses->from('promotions.coupon_uses as u')->selectRaw('count(*)')
                ->whereColumn('u.coupon_id', 'a.coupon_id')->whereColumn('u.customer_id', 'a.customer_id'), 'uses');

        if ($beforeAt !== null && $beforeCustomerId !== null) {
            $query->whereRaw('(a.assigned_at, a.customer_id) < (?, ?)', [Moment::of($beforeAt), strtolower($beforeCustomerId)]);
        }

        return array_values(array_map(static fn (stdClass $row): array => [
            'customerId' => (string) $row->customer_id,
            'assignedAt' => CarbonImmutable::parse((string) $row->assigned_at),
            'segmentId' => $row->segment_id === null ? null : (string) $row->segment_id,
            'segmentNameAr' => $row->name_ar === null ? null : (string) $row->name_ar,
            'segmentNameEn' => $row->name_en === null ? null : (string) $row->name_en,
            'uses' => (int) $row->uses,
        ], $query->orderByDesc('a.assigned_at')->orderByDesc('a.customer_id')->limit($limit)->get()->all()));
    }

    private static function toRow(stdClass $row, DateTimeImmutable $now): CouponRow
    {
        $currency = (string) $row->currency_code;
        $startsAt = CarbonImmutable::parse((string) $row->starts_at);
        $endsAt = $row->ends_at === null ? null : CarbonImmutable::parse((string) $row->ends_at);
        $maxUsesTotal = $row->max_uses_total === null ? null : (int) $row->max_uses_total;

        return new CouponRow(
            (string) $row->id,
            (string) $row->code,
            (string) $row->name_ar,
            (string) $row->name_en,
            CouponEligibility::from((string) $row->eligibility),
            DiscountKind::from((string) $row->discount_kind),
            $row->percent === null ? null : (int) $row->percent,
            $row->amount_minor === null ? null : Money::of((int) $row->amount_minor, $currency),
            $currency,
            $startsAt,
            $endsAt,
            (int) $row->uses_total,
            $maxUsesTotal,
            (bool) $row->active,
            CouponStates::of((bool) $row->active, $startsAt, $endsAt, (int) $row->uses_total, $maxUsesTotal, $now),
            CarbonImmutable::parse((string) $row->created_at),
        );
    }
}
