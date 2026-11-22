<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Which points of which lots an account's holds keep (loyalty.md §9.6): each hold, oldest first, takes
 * from the lots a redemption at its own moment would have spent - live then, the one expiring first
 * first - after the holds before it. So a hold keeps exactly the points its confirm will spend: they
 * are spent, expired, deducted and used to cover by nothing else, while every other point of the lot
 * goes on as before. Read under the account's lock by whoever writes.
 */
final readonly class HeldLots
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    /**
     * @return array<int, int> lot id => points kept for holds
     */
    public function of(string $accountId): array
    {
        $holds = $this->db->table('loyalty.redemption_holds')->where('account_id', $accountId)->orderBy('reserved_at')->orderBy('order_id')->get(['points', 'reserved_at']);

        if ($holds->isEmpty()) {
            return [];
        }

        $lots = $this->db->table('loyalty.lots')
            ->where('account_id', $accountId)
            ->where('points_left', '>', 0)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get(['id', 'points_left', 'expires_at']);

        $left = [];

        foreach ($lots as $lot) {
            $left[(int) $lot->id] = (int) $lot->points_left;
        }

        $kept = [];

        foreach ($holds as $hold) {
            $need = (int) $hold->points;
            $at = new DateTimeImmutable((string) $hold->reserved_at);

            foreach ($lots as $lot) {
                if ($need === 0) {
                    break;
                }

                $id = (int) $lot->id;

                if ($left[$id] === 0 || new DateTimeImmutable((string) $lot->expires_at) <= $at) {
                    continue;
                }

                $take = min($need, $left[$id]);
                $kept[$id] = ($kept[$id] ?? 0) + $take;
                $left[$id] -= $take;
                $need -= $take;
            }
        }

        return $kept;
    }

    /** The account's live points now that no hold keeps: what may be quoted, spent or deducted. */
    public function freePoints(string $accountId, DateTimeImmutable $now): int
    {
        $kept = $this->of($accountId);
        $free = 0;

        foreach ($this->db->table('loyalty.lots')->where('account_id', $accountId)->where('points_left', '>', 0)->where('expires_at', '>', Moment::of($now))->get(['id', 'points_left']) as $lot) {
            $free += max(0, (int) $lot->points_left - ($kept[(int) $lot->id] ?? 0));
        }

        return $free;
    }
}
