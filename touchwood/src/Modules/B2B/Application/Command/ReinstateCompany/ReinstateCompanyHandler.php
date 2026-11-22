<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ReinstateCompany;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Audit\StaffCompanyAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Events\CompanyEvents;
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
 * **`ReinstateCompany`** (b2b.md §3.2, §4.1): back to the status it held when it was suspended —
 * never simply `PENDING` — with a reason, so the history reads as a conversation. Audited on the
 * company. **No email**: a reinstatement is the suspension notice disappearing (§2.3).
 */
final readonly class ReinstateCompanyHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_SUSPEND;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCompanyAction $action,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private CompanyEvents $events,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws CompanyNotFound|InvalidCompanyAttribute|InvalidCompanyStatus|Unauthorized
     */
    public function handle(ReinstateCompany $command): void
    {
        [$scope, $found] = $this->action->about(self::PERMISSION, $command->companyId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $staffId = $this->action->decider(self::PERMISSION);
        $reason = Remark::of('reason', $command->reason);

        $this->db->transaction(function () use ($found, $staffId, $reason): void {
            $this->applications->lockAccount($found->customerId());
            $company = $this->companies->byId($found->id()) ?? throw new CompanyNotFound;
            $from = $company->status();

            $company->reinstate($staffId, $reason, CarbonImmutable::now());

            $this->companies->update($company);
            $this->platform->recordAudit(StaffCompanyAudit::statusChanged('b2b.company.reinstated', $company, $from, $reason));
            $this->events->statusChanged($company, $from);
        }, 3);
    }
}
