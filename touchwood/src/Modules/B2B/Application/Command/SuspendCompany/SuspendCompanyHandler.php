<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\SuspendCompany;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\B2B\Application\Audit\StaffCompanyAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Events\CompanyEvents;
use Modules\B2B\Application\Staff\CompanyMessages;
use Modules\B2B\Application\Staff\StaffCompanyAction;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`SuspendCompany`** (b2b.md §3.2, §4.1): from any status, with a reason the customer is told, and
 * remembering the status it came from so reinstating returns there. Its open draft freezes (amendment
 * 9(a)); an application waiting stays waiting, to be decided once the company is reinstated.
 * Audited on the company; the customer is emailed once it has committed.
 */
final readonly class SuspendCompanyHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_SUSPEND;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCompanyAction $action,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private CompanyMessages $messages,
        private CompanyEvents $events,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws CompanyNotFound|InvalidCompanyAttribute|InvalidCompanyStatus|Unauthorized
     */
    public function handle(SuspendCompany $command): void
    {
        [$scope, $found] = $this->action->about(self::PERMISSION, $command->companyId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $staffId = $this->action->decider(self::PERMISSION);
        $reason = Remark::of('reason', $command->reason);

        $this->db->transaction(function () use ($found, $staffId, $reason): void {
            $this->applications->lockAccount($found->customerId());
            $company = $this->companies->byId($found->id()) ?? throw new CompanyNotFound;
            $from = $company->status();

            $company->suspend($staffId, $reason, CarbonImmutable::now());

            $this->companies->update($company);
            $this->platform->recordAudit(StaffCompanyAudit::statusChanged('b2b.company.suspended', $company, $from, $reason));
            $this->events->statusChanged($company, $from);
            // The email names the company's store: an account may hold one in each (amendment 19(c)).
            $store = $company->homeStoreId();
            $this->messages->afterCommit($company->customerId(), static fn (SecurityMessages $messages, CustomerDto $customer) => $messages->companySuspended($customer, $reason->value, $store));
        }, 3);
    }
}
