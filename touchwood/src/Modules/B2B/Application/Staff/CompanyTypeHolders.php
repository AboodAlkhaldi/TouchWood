<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Staff;

use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Public\Enums\CompanyStatus;
use Shared\Application\PermissionScope;

/**
 * Every company holding one listed type moved to another (b2b.md §1.3, amendments 5 and 11): a type
 * replaced when it is deactivated, or a transfer between two active types. Each move is a staff
 * correction (`CompanyTypeCorrection`): the company changes, never an application it sent, nobody
 * goes back to `PENDING`, each company is audited, and an open draft follows only while it still held
 * the company's type. **A suspended company is skipped** and keeps the old type (10(h)).
 *
 * Runs inside the caller's transaction, after the store's type-list lock — B2B's one lock order:
 * then each account in account order, then its company, read again under its locks, since it may have
 * moved since the list was read.
 */
final readonly class CompanyTypeHolders
{
    public function __construct(
        private CompanyTypeCorrection $correction,
        private CompanyRepository $companies,
        private CompanyTypeRepository $companyTypes,
        private ApplicationRepository $applications,
    ) {}

    /**
     * The type the holders of `$from` may move to: another active type of the same store. Unknown,
     * another store's, or `$from` itself is `InvalidCompanyAttribute`, as an unknown type is
     * everywhere (§7); an inactive one is `CompanyTypeInactive`.
     *
     * @param  string  $field  what the screen calls it: "replacement" on a deactivation, "target" on a
     *                         transfer
     *
     * @throws CompanyTypeInactive|InvalidCompanyAttribute
     */
    public function target(CompanyType $from, string $toTypeId, string $field): CompanyType
    {
        $to = $this->companyTypes->find($toTypeId);

        if ($to === null || $to->storeId() !== $from->storeId() || $to->id() === $from->id()) {
            throw new InvalidCompanyAttribute($field, 'another type of the same store');
        }

        if (! $to->isActive()) {
            throw new CompanyTypeInactive;
        }

        return $to;
    }

    /**
     * @param  string  $action  what each company's audit entry is called
     * @return int how many companies moved
     */
    public function move(CompanyType $from, CompanyType $to, PermissionScope $scope, string $action): int
    {
        $held = CompanyTypeChoice::listed($from->id());
        $moved = 0;

        foreach ($this->companies->holdersOf($from->id()) as $customerId) {
            $this->applications->lockAccount($customerId);
            $company = $this->companies->forCustomerLocked($customerId, $from->storeId());

            if ($company === null || $company->status() === CompanyStatus::Suspended || ! $company->details()->type->equals($held)) {
                continue;
            }

            $this->correction->apply($company, CompanyTypeChoice::listed($to->id()), $action, $scope);
            $moved++;
        }

        return $moved;
    }
}
