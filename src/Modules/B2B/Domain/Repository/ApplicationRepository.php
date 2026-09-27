<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Repository;

use Modules\B2B\Domain\Model\Application;

/**
 * Applications, every one kept (b2b.md §1.2), each with its files. Writing one writes its files in
 * the same call, so an application and what it holds never disagree.
 */
interface ApplicationRepository
{
    public function nextId(): string;

    /** A read with no lock. */
    public function find(string $applicationId): ?Application;

    /** Locks the row: for a change, inside its transaction. */
    public function byId(string $applicationId): ?Application;

    /** The account's one open application — a draft or one sent and waiting — if any. */
    public function openFor(string $customerId): ?Application;

    /**
     * Every application the company has sent, newest first: what staff compare (b2b.md §1.2).
     *
     * @return list<Application>
     */
    public function historyOf(string $companyId): array;

    public function add(Application $application): void;

    public function update(Application $application): void;

    /**
     * A draft thrown away by the customer (owner, 2026-09-27). Its references to its files go with
     * it; the files themselves are Platform's, and the use case asks Platform to delete the ones
     * no other application still holds.
     */
    public function delete(string $applicationId): void;
}
