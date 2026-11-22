<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Promotions\Domain\Model\Segment;
use Modules\Promotions\Domain\Model\SegmentTerms;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Public\Enums\SegmentAudience;
use Modules\Promotions\Public\Enums\SegmentRule;
use Modules\Promotions\Public\Enums\SegmentType;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * `Segments` in SQL, no Eloquent model (§1.8). Members are rewritten as a set, in pages of a thousand
 * — a fixed number of queries per thousand members, never one per customer (handoff §5.4).
 */
final readonly class DatabaseSegments implements Segments
{
    private const int CHUNK = 1000;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function storeOf(string $segmentId): ?StoreId
    {
        $store = Ulids::valid($segmentId) ? $this->db->table('promotions.segments')->where('id', strtolower($segmentId))->value('store_id') : null;

        return $store === null ? null : StoreId::fromString((string) $store);
    }

    public function byId(StoreId $store, string $segmentId): ?Segment
    {
        return Ulids::valid($segmentId) ? $this->toSegment($this->segments($store)->where('id', strtolower($segmentId))->first()) : null;
    }

    public function lock(StoreId $store, string $segmentId): ?Segment
    {
        return Ulids::valid($segmentId) ? $this->toSegment($this->segments($store)->where('id', strtolower($segmentId))->lockForUpdate()->first()) : null;
    }

    public function create(string $segmentId, StoreId $store, SegmentTerms $terms, DateTimeImmutable $at): void
    {
        $this->db->table('promotions.segments')->insert([
            'id' => $segmentId,
            'store_id' => $store->value,
            ...self::termsRow($terms),
            'type' => $terms->type->value,
            'audience' => $terms->audience->value,
            'created_at' => Moment::of($at),
            'updated_at' => Moment::of($at),
        ]);
    }

    public function change(Segment $segment, SegmentTerms $terms, DateTimeImmutable $at): void
    {
        $this->segments(StoreId::fromString($segment->storeId))->where('id', $segment->id)->update([...self::termsRow($terms), 'updated_at' => Moment::of($at)]);
    }

    public function delete(Segment $segment): void
    {
        $this->segments(StoreId::fromString($segment->storeId))->where('id', $segment->id)->delete();
    }

    public function memberIds(string $segmentId): array
    {
        return array_values(array_map(strval(...), $this->db->table('promotions.segment_members')->where('segment_id', $segmentId)->orderBy('customer_id')->pluck('customer_id')->all()));
    }

    public function replaceMembers(Segment $segment, array $customerIds, DateTimeImmutable $at): array
    {
        $now = $this->memberIds($segment->id);
        $removed = array_values(array_diff($now, $customerIds));
        $added = array_values(array_diff(array_unique($customerIds), $now));

        foreach (array_chunk($removed, self::CHUNK) as $chunk) {
            $this->db->table('promotions.segment_members')->where('segment_id', $segment->id)->whereIn('customer_id', $chunk)->delete();
        }

        foreach (array_chunk($added, self::CHUNK) as $chunk) {
            $this->db->table('promotions.segment_members')->insert(array_map(
                static fn (string $customerId): array => ['segment_id' => $segment->id, 'customer_id' => $customerId, 'added_at' => Moment::of($at)],
                $chunk,
            ));
        }

        return ['added' => count($added), 'removed' => count($removed)];
    }

    public function markRefreshed(Segment $segment, DateTimeImmutable $at): void
    {
        $this->segments(StoreId::fromString($segment->storeId))->where('id', $segment->id)->update(['refreshed_at' => Moment::of($at)]);
    }

    public function membersAmong(string $segmentId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return array_values(array_map(strval(...), $this->db->table('promotions.segment_members')->where('segment_id', $segmentId)->whereIn('customer_id', $customerIds)->pluck('customer_id')->all()));
    }

    public function membersPage(string $segmentId, ?string $after, int $limit): array
    {
        return array_values(array_map(strval(...), $this->db->table('promotions.segment_members')
            ->where('segment_id', $segmentId)
            ->when($after !== null, fn (Builder $query) => $query->where('customer_id', '>', strtolower((string) $after)))
            ->orderBy('customer_id')
            ->limit($limit)
            ->pluck('customer_id')
            ->all()));
    }

    public function pageAcrossStores(?string $afterId, int $limit): array
    {
        return array_values(array_filter(array_map(
            $this->toSegment(...),
            $this->db->table('promotions.segments')->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))->orderBy('id')->limit($limit)->get()->all(),
        )));
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function termsRow(SegmentTerms $terms): array
    {
        return [
            'name_ar' => $terms->nameAr,
            'name_en' => $terms->nameEn,
            'rule' => $terms->rule?->value,
            'rule_months' => $terms->ruleMonths,
            'rule_orders' => $terms->ruleOrders,
        ];
    }

    private function segments(StoreId $store): Builder
    {
        return $this->db->table('promotions.segments')->where('store_id', $store->value);
    }

    private function toSegment(mixed $row): ?Segment
    {
        if (! $row instanceof stdClass) {
            return null;
        }

        return new Segment(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->name_ar,
            (string) $row->name_en,
            SegmentType::from((string) $row->type),
            SegmentAudience::from((string) $row->audience),
            $row->rule === null ? null : SegmentRule::from((string) $row->rule),
            $row->rule_months === null ? null : (int) $row->rule_months,
            $row->rule_orders === null ? null : (int) $row->rule_orders,
            $row->refreshed_at === null ? null : CarbonImmutable::parse((string) $row->refreshed_at),
        );
    }
}
