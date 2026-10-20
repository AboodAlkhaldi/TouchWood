<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\Model\Quote;
use Modules\Sales\Domain\Repository\Quotes;
use stdClass;

/** `Quotes` in SQL, no Eloquent model (sales.md §5). */
final readonly class DatabaseQuotes implements Quotes
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(Quote $quote): void
    {
        $this->db->table('sales.quotes')->insert([
            'id' => $quote->id,
            'store_id' => $quote->storeId,
            'customer_id' => $quote->customerId,
            'address_id' => $quote->addressId,
            'coupon_code' => $quote->couponCode,
            'use_points' => $quote->usePoints,
            'points_asked' => $quote->pointsAsked,
            'way_to_pay' => $quote->wayToPay,
            'result' => json_encode($quote->result, JSON_THROW_ON_ERROR),
            'expires_at' => Moment::of($quote->expiresAt),
            'created_at' => Moment::of($quote->createdAt),
        ]);
    }

    public function find(string $quoteId): ?Quote
    {
        return $this->read(strtolower($quoteId), false);
    }

    public function lock(string $quoteId): ?Quote
    {
        return $this->read(strtolower($quoteId), true);
    }

    private function read(string $quoteId, bool $lock): ?Quote
    {
        $query = $this->db->table('sales.quotes')->where('id', $quoteId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        /** @var array<string, mixed> $result */
        $result = json_decode((string) $row->result, true, 512, JSON_THROW_ON_ERROR);

        return new Quote(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->customer_id,
            (string) $row->address_id,
            $row->coupon_code === null ? null : (string) $row->coupon_code,
            (bool) $row->use_points,
            $row->points_asked === null ? null : (int) $row->points_asked,
            (string) $row->way_to_pay,
            $result,
            CarbonImmutable::parse((string) $row->expires_at),
            CarbonImmutable::parse((string) $row->created_at),
        );
    }

    public function deleteExpiredBefore(DateTimeImmutable $at): int
    {
        // A placed quote stays with its order: placing again answers the order placed from it (§1.3). One
        // being placed now is locked and left alone, so its order is never missed for a deleted quote.
        return $this->db->delete(<<<'SQL'
            DELETE FROM sales.quotes WHERE id IN (
                SELECT q.id FROM sales.quotes q
                WHERE q.expires_at < ?
                    AND NOT EXISTS (SELECT 1 FROM sales.orders o WHERE o.quote_id = q.id)
                FOR UPDATE SKIP LOCKED
            )
            SQL, [Moment::of($at)]);
    }
}
