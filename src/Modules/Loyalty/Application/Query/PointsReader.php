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

    /**
     * The store's points accounts, most live points first, then by id, from after the cursor; only
     * those of `$customerIds` when it is given (a search). One query.
     *
     * @param  list<string>|null  $customerIds
     * @param  array{0: int, 1: string}|null  $after  the last row's live points and account id
     * @return list<HolderRow>
     */
    public function holders(string $storeId, DateTimeImmutable $now, ?array $customerIds, ?array $after, int $limit): array;

    /**
     * The live lots of these accounts, soonest first. One query.
     *
     * @param  list<string>  $accountIds
     * @return list<LiveLotRow>
     */
    public function liveLotsOf(array $accountIds, DateTimeImmutable $now): array;

    /**
     * One customer's accounts in these stores, keyed by store id. One query.
     *
     * @param  list<string>  $storeIds
     * @return array<string, string> store id => account id
     */
    public function accountsOf(string $customerId, array $storeIds): array;
}
