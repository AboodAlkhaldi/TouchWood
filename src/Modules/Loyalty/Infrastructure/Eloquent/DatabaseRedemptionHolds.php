<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Loyalty\Domain\Model\RedemptionHold;
use Modules\Loyalty\Domain\Repository\RedemptionHolds;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

final readonly class DatabaseRedemptionHolds implements RedemptionHolds
{
    private const string TABLE = 'loyalty.redemption_holds';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function of(string $orderId, bool $lock = false): ?RedemptionHold
    {
        if (! Ulids::valid($orderId)) {
            return null;
        }

        $query = $this->db->table(self::TABLE)->where('order_id', strtolower($orderId));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? new RedemptionHold(
            (string) $row->order_id,
            (string) $row->account_id,
            (string) $row->store_id,
            (int) $row->points,
            (int) $row->discount_minor,
            (string) $row->currency_code,
            (int) $row->redemption_cap_percent,
            new DateTimeImmutable((string) $row->reserved_at),
        ) : null;
    }

    public function heldBy(string $accountId): int
    {
        return (int) $this->db->table(self::TABLE)->where('account_id', $accountId)->sum('points');
    }

    public function freePoints(string $accountId, DateTimeImmutable $now): int
    {
        return (new HeldLots($this->db))->freePoints($accountId, $now);
    }

    public function add(RedemptionHold $hold): void
    {
        $this->db->table(self::TABLE)->insert([
            'order_id' => strtolower($hold->orderId),
            'account_id' => $hold->accountId,
            'store_id' => $hold->storeId,
            'points' => $hold->points,
            'discount_minor' => $hold->discountMinor,
            'currency_code' => $hold->currencyCode,
            'redemption_cap_percent' => $hold->capPercent,
            'reserved_at' => Moment::of($hold->reservedAt),
        ]);
    }

    public function drop(string $orderId): bool
    {
        return Ulids::valid($orderId) && $this->db->table(self::TABLE)->where('order_id', strtolower($orderId))->delete() > 0;
    }
}
