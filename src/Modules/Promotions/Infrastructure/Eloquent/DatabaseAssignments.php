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

    public function assign(Coupon $coupon, array $customerIds, ?string $segmentId, DateTimeImmutable $at, string $assignmentId): array
    {
        $added = [];

        // A thousand at a time, as one bound array each; the rows a customer had already are left as they
        // are, and only the new ones come back. The array literal is safe written so because every id is a
        // segment member's (`Segments::memberIds`): a ULID, never a comma, quote or brace.
        foreach (array_chunk(array_values(array_unique($customerIds)), self::CHUNK) as $chunk) {
            $rows = $this->db->select(
                'INSERT INTO promotions.coupon_assignments (coupon_id, store_id, customer_id, segment_id, assigned_at, assignment_id)'
                .' SELECT ?, ?, customer_id, ?, ?, ? FROM unnest(?::text[]) AS customer_id'
                .' ON CONFLICT DO NOTHING RETURNING customer_id',
                [$coupon->id, $coupon->storeId, $segmentId, Moment::of($at), $assignmentId, '{'.implode(',', $chunk).'}'],
            );

            foreach ($rows as $row) {
                $added[] = (string) $row->customer_id;
            }
        }

        return $added;
    }

    public function any(Coupon $coupon): bool
    {
        return $this->db->table('promotions.coupon_assignments')->where('coupon_id', $coupon->id)->where('store_id', $coupon->storeId)->exists();
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
