<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\CorrectCompanyType;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Staff\CompanyTypeCorrection;
use Modules\B2B\Application\Staff\StaffCompanyAction;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`CorrectCompanyType`** (b2b.md §3.2, amendments 2, 8(b) and 10): rewrite an "Other" in the right
 * words, or move the company to a listed type of its home store. It changes the company, never the
 * application it sent, and does not send it back to `PENDING`. Refused while the company is
 * suspended (10(h)). A deactivated type is activated first — confirmed, and by someone who may also
 * deactivate that store's company types (10(b)). An approved company is never made "Other"
 * (`Company::correctType`, amendment 13(b)): it was approved with a listed type.
 */
final readonly class CorrectCompanyTypeHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_CORRECT_TYPE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCompanyAction $action,
        private CompanyTypeCorrection $correction,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private StoreTypeListsRepository $lists,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws CompanyNotFound|CompanySuspended|CompanyTypeInactive|InvalidCompanyAttribute|Unauthorized
     */
    public function handle(CorrectCompanyType $command): void
    {
        [$scope, $found] = $this->action->about(self::PERMISSION, $command->companyId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $to = CompanyTypeCorrection::choice($command->typeId, $command->other);

        $this->db->transaction(function () use ($found, $to, $scope, $command): void {
            // B2B's one lock order: the store's lists first, since a listed type may be activated.
            if ($to->typeId !== null) {
                $this->lists->lockLists($found->homeStoreId());
            }

            $this->applications->lockAccount($found->customerId());
            $company = $this->companies->forCustomerLocked($found->customerId()) ?? throw new CompanyNotFound;

            $this->correction->apply($company, $to, 'b2b.company.type_corrected', $scope, $command->confirmReactivation);
        }, 3);
    }
}
