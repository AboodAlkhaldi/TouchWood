<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ApproveCompany;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\B2B\Application\Audit\StaffCompanyAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Events\CompanyEvents;
use Modules\B2B\Application\Staff\CompanyMessages;
use Modules\B2B\Application\Staff\StaffCompanyAction;
use Modules\B2B\Domain\Exception\CompanyAccountDeleted;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Exception\CompanyTypeNotSet;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`ApproveCompany`** (b2b.md §3.2, §4.1): decides the application a `PENDING` company sent — the
 * company is `APPROVED` and may order. An optional note goes to the customer with the approval email
 * (amendment 1), sent once the approval has committed.
 *
 * **No choice about the type** (amendment 11(a)): when the application's type was deactivated after
 * it was sent, the staff member who deactivated it already decided for its holders — the company
 * carries the replacement, or the old type if it was left — and approving keeps what it carries. The
 * application is marked for the reviewer, for information only (ViewCompany, ListCompanies).
 *
 * **Never as "Other"** (amendment 13(b)): `Company::approve` refuses it with `CompanyTypeNotSet`,
 * and staff correct the type to a listed one first. **Never for an erased account** (13(e)):
 * nobody can sign in to it again, so its waiting application is rejected instead
 * (`CompanyAccountDeleted`).
 *
 * Audited on the application, as sending is.
 */
final readonly class ApproveCompanyHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_REVIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCompanyAction $action,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private CompanyMessages $messages,
        private CompanyEvents $events,
        private AccessApi $access,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws CompanyNotFound|InvalidCompanyAttribute|InvalidCompanyStatus|Unauthorized
     * @throws CompanyAccountDeleted|CompanyTypeNotSet
     */
    public function handle(ApproveCompany $command): void
    {
        [$scope, $found] = $this->action->about(self::PERMISSION, $command->companyId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $staffId = $this->action->decider(self::PERMISSION);
        $note = $command->note === null || trim($command->note) === '' ? null : Remark::of('note', $command->note);

        $this->db->transaction(function () use ($found, $staffId, $note): void {
            $this->applications->lockAccount($found->customerId());
            $company = $this->companies->forCustomerLocked($found->customerId()) ?? throw new CompanyNotFound;
            $waiting = $this->applications->openFor($company->customerId());

            // Only a PENDING company has something to decide (§4.1).
            if ($company->status() !== CompanyStatus::Pending || $waiting?->state() !== ApplicationState::Submitted) {
                throw new InvalidCompanyStatus('approved', $company->status()->value);
            }

            // Access keeps an erased account's row, marked; B2B has already emptied the company.
            if ($this->access->customer($company->customerId())?->anonymized === true) {
                throw new CompanyAccountDeleted;
            }

            $now = CarbonImmutable::now();
            $waiting->approve($staffId, $note, $now);
            $company->approve($staffId, $now);

            $this->applications->update($waiting);
            $this->companies->update($company);
            $this->platform->recordAudit(StaffCompanyAudit::approved($waiting, $note, $company->homeStoreId()));
            $this->events->statusChanged($company, CompanyStatus::Pending);
            $this->messages->afterCommit($company->customerId(), static fn (SecurityMessages $messages, CustomerDto $customer) => $messages->companyApproved($customer, $note?->value));
        }, 3);
    }
}
