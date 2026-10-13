<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use DateTimeImmutable;

/**
 * The ledger as the screens read it (loyalty.md §3), newest first and a page at a time by keyset
 * (handoff §5.4): `occurred_at`, then `id`, so a page never skips or repeats an entry. Reads only.
 */
interface PointsReader
{
    /**
     * An account's history, newest first, after the cursor.
     *
     * @return list<PointsHistoryRow>
     */
    public function history(string $accountId, ?DateTimeImmutable $beforeAt, ?int $beforeId, int $limit): array;

    /**
     * A store's redemptions, newest first, after the cursor.
     *
     * @return list<RedemptionRow>
     */
    public function redemptions(string $storeId, ?DateTimeImmutable $beforeAt, ?int $beforeId, int $limit): array;
}
