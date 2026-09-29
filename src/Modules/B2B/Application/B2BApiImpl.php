<?php

declare(strict_types=1);

namespace Modules\B2B\Application;

use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\B2B\Public\Dto\CompanyDto;
use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * b2b.md §2.1. Plain reads: no lock, no transaction — each answers one question about one moment.
 */
final readonly class B2BApiImpl implements B2BApi
{
    public function __construct(
        private CompanyRepository $companies,
        private CompanyTypeRepository $companyTypes,
    ) {}

    public function company(string $customerId): ?CompanyDto
    {
        $company = $this->companies->forCustomer($customerId);

        return $company === null ? null : $this->toDto($company);
    }

    public function status(string $customerId): ?CompanyStatus
    {
        return $this->companies->forCustomer($customerId)?->status();
    }

    public function isApproved(string $customerId): bool
    {
        return $this->companies->forCustomer($customerId)?->mayOrder() ?? false;
    }

    private function toDto(Company $company): CompanyDto
    {
        $choice = $company->details()->type;
        // A type row is read, never locked: a lock on it waits on every company that points at it
        // (the review of step 4).
        $type = $choice->typeId === null ? null : $this->companyTypes->find($choice->typeId);

        return new CompanyDto(
            $company->id(),
            $company->customerId(),
            $company->details()->name->value,
            $type?->name()->ar,
            $type?->name()->en,
            $choice->other,
            $company->status(),
            $company->statusReason()?->value,
        );
    }
}
