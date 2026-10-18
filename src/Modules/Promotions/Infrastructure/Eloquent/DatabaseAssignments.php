<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Repository\Assignments;
use Modules\Promotions\Public\Enums\SegmentAudience;

/** `Assignments` in SQL: written in chunks of a thousand, existing rows left as they are. */
final readonly class DatabaseAssignments implements Assignments
{
    private const int CHUNK = 1000;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function assign(Coupon $coupon, array $customerIds, ?string $segmentId, DateTimeImmutable $at): int
    {
        $added = 0;

        foreach (array_chunk(array_values(array_unique($customerIds)), self::CHUNK) as $chunk) {
            $added += $this->db->table('promotions.coupon_assignments')->insertOrIgnore(array_map(static fn (string $customerId): array => [
                'coupon_id' => $coupon->id,
                'store_id' => $coupon->storeId,
                'customer_id' => $customerId,
                'segment_id' => $segmentId,
                'assigned_at' => Moment::of($at),
            ], $chunk));
        }

        return $added;
    }

    public function remove(Coupon $coupon, string $customerId): bool
    {
        return $this->db->table('promotions.coupon_assignments')
            ->where('coupon_id', $coupon->id)
            ->where('store_id', $coupon->storeId)
            ->where('customer_id', $customerId)
            ->delete() > 0;
    }

    public function segmentAudiences(Coupon $coupon): array
    {
        return array_values(array_map(
            static fn (mixed $audience): SegmentAudience => SegmentAudience::from((string) $audience),
            $this->db->table('promotions.coupon_assignments as a')
                ->join('promotions.segments as s', 's.id', '=', 'a.segment_id')
                ->where('a.coupon_id', $coupon->id)
                ->distinct()
                ->orderBy('s.audience')
                ->pluck('s.audience')
                ->all(),
        ));
    }
}
