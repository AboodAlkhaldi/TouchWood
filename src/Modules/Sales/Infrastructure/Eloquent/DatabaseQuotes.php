<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
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
        $row = $this->db->table('sales.quotes')->where('id', strtolower($quoteId))->first();

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
        // A placed quote stays with its order: placing again answers the order placed from it (§1.3).
        return $this->db->table('sales.quotes')
            ->where('expires_at', '<', Moment::of($at))
            ->whereNotExists(fn (Builder $orders) => $orders->selectRaw('1')->from('sales.orders')->whereColumn('sales.orders.quote_id', 'sales.quotes.id'))
            ->delete();
    }
}
