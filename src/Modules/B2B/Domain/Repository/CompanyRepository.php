<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Repository;

use Modules\B2B\Domain\Model\Company;

/**
 * Companies, one per company account (b2b.md §1.1).
 */
interface CompanyRepository
{
    public function nextId(): string;

    /** A read with no lock. */
    public function find(string $companyId): ?Company;

    /** Locks the row: for a change, inside its transaction. */
    public function byId(string $companyId): ?Company;

    /** The account's company, if it has one yet; no lock. */
    public function forCustomer(string $customerId): ?Company;

    /** The account's company, locked: for a change, inside its transaction. */
    public function forCustomerLocked(string $customerId): ?Company;

    /**
     * The accounts whose company holds this listed type, in account order — the one order a change
     * to many of them takes their locks in (b2b.md §1.3, amendment 5). No lock.
     *
     * @return list<string> customer ids
     */
    public function holdersOf(string $companyTypeId): array;

    public function add(Company $company): void;

    public function update(Company $company): void;
}
