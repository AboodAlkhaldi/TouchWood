<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Repository;

use Modules\B2B\Domain\Exception\ApplicationAlreadyOpen;
use Modules\B2B\Domain\Model\Application;

/**
 * Applications, every one kept (b2b.md §1.2), each with its files — and with the flags and requests
 * a rejection gave it, or the answers a draft gives (amendment 4). Writing one writes all of them in
 * the same call, so an application and what it holds never disagree.
 */
interface ApplicationRepository
{
    public function nextId(): string;

    /** A read with no lock. */
    public function find(string $applicationId): ?Application;

    /** Locks the row: for a change, inside its transaction. */
    public function byId(string $applicationId): ?Application;

    /**
     * The account's one open application **in this store** — a draft or one sent and waiting — if
     * any (amendment 18: one open application per account and store).
     */
    public function openFor(string $customerId, string $storeId): ?Application;

    /**
     * Every open application of the account, whatever its store — for anonymizing it (§1.1).
     *
     * @return list<Application>
     */
    public function openAllFor(string $customerId): array;

    /**
     * Every application the company has sent, newest first: what staff compare (b2b.md §1.2).
     *
     * @return list<Application>
     */
    public function historyOf(string $companyId): array;

    /**
     * The company's last application sent — never a draft — whose flags and requests the next
     * draft meets when it was rejected (amendment 4); null when it has sent none.
     */
    public function lastSent(string $companyId): ?Application;

    /**
     * Serialises everything the account does to its applications and its companies, until the
     * transaction ends: one open application and one company per account and store (§1.1, §1.2,
     * amendment 18) are decided here before the database's unique indexes ever have to. Every one
     * of the company's own use cases that writes takes it first, then reads. One lock for the whole
     * account, across its stores (amendment 20(d)).
     */
    public function lockAccount(string $customerId): void;

    /**
     * The same lock, shared: readers do not wait for each other, only for a writer, and no writer
     * commits while they read — so a read of several tables sees them as of one moment.
     */
    public function lockAccountForReading(string $customerId): void;

    /**
     * @throws ApplicationAlreadyOpen the account already has an open application — which the lock
     *                                above means only a caller that skipped it can reach
     */
    public function add(Application $application): void;

    public function update(Application $application): void;

    /**
     * Which of these files any application still holds, as a document or as an answer, drafts
     * included (§1.4): B2B asks Platform to delete a file only once none does.
     *
     * @param  list<string>  $mediaIds
     * @return list<string>
     */
    public function stillHeld(array $mediaIds): array;

    /**
     * Whether one of the account's own applications holds this file, as a document or an answer —
     * the only files the company may open (§1.4, amendment 5).
     */
    public function accountHolds(string $customerId, string $mediaId): bool;

    /**
     * A draft thrown away by the customer (owner, 2026-09-27). Its references to its files go with
     * it; the files themselves are Platform's, and the use case asks Platform to delete the ones
     * no other application still holds.
     */
    public function delete(string $applicationId): void;
}
