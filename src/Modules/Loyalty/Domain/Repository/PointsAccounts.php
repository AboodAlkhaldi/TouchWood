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
 * order everywhere (lesson 37) — and expires the account's dead lots before anything else, which is
 * why the lock and the expiry are one call.
 */
interface PointsAccounts
{
    /** The customer's account in the store, without locking; null when none exists yet. */
    public function find(string $customerId, StoreId $store): ?PointsAccount;

    /**
     * The customer's account in the store, made if absent, locked until the caller's transaction
     * ends, and with its dead lots expired (§1.10): each such lot loses its remaining points as one
     * EXPIRED entry with its movement, and the balance falls by them. Answers the account as it is
     * after that. Inserted only if absent, so two first writes cannot make two accounts; refused
     * outside a transaction, where a lock would end with its statement.
     */
    public function lockOrCreate(string $customerId, StoreId $store, DateTimeImmutable $now): PointsAccount;

    /**
     * The account's live lots — points left, date not passed — the one expiring first first, equal
     * dates the older lot first (§1.3). A read: nothing is locked or written.
     *
     * @return list<Lot>
     */
    public function liveLots(PointsAccount $account, DateTimeImmutable $now): array;

    /** What the account can use now: the live lots' points. A read: nothing is written (§1.1). */
    public function liveBalance(PointsAccount $account, DateTimeImmutable $now): int;
}
