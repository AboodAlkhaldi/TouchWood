<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Repository;

use DateTimeImmutable;
use Modules\Loyalty\Domain\Model\Lot;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Shared\Domain\ValueObject\StoreId;

/**
 * The points accounts and their lots (loyalty.md §1.1, §1.3, §1.10), the store named in every call
 * (§1.12). One change at a time: every write locks the account first, then its lots — the one lock
 * order everywhere (lesson 37) — and expires the account's dead lots before anything else.
 */
interface PointsAccounts
{
    /** The customer's account in the store, without locking; null when none exists yet. */
    public function find(string $customerId, StoreId $store): ?PointsAccount;

    /**
     * The customer's account in the store, created if absent and then locked until the caller's
     * transaction ends. Inserted only if absent, so two first writes cannot make two accounts.
     */
    public function lockOrCreate(string $customerId, StoreId $store): PointsAccount;

    /**
     * The account's live lots — points left, date not passed — the one expiring first first, equal
     * dates the older lot first (§1.3). A read: nothing is locked or written.
     *
     * @return list<Lot>
     */
    public function liveLots(PointsAccount $account, DateTimeImmutable $now): array;

    /** What the account can use now: the live lots' points. A read: nothing is written (§1.1). */
    public function liveBalance(PointsAccount $account, DateTimeImmutable $now): int;

    /**
     * Expires the account's lots whose date has passed (§1.10): each loses its remaining points as one
     * EXPIRED entry, with its movement, and the balance falls by them. The account must be locked by
     * lockOrCreate in the same transaction. Answers the points expired; a lot already empty needs none.
     */
    public function expireDeadLots(PointsAccount $account, DateTimeImmutable $now): int;
}
