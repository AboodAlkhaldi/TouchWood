<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewCompany;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Query\ApplicationViews;
use Modules\B2B\Application\Staff\StaffCompanyAction;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`ViewCompany`** (b2b.md §3.2): a company of the reader's own stores, as staff review it — its
 * details, status and reason, the account holder read from Access, and **the applications it sent**,
 * newest first, each with its values, papers, flags, requests and answers, and who decided it.
 * **Never a draft**: nothing is reviewed until it is sent (§1.2). A waiting application whose company
 * type was deactivated since it was sent is marked, so the reviewer decides with that in front of
 * them (§1.3).
 *
 * Several reads, of one moment: under the account's lock, shared, as the company's own page is read.
 */
final readonly class ViewCompanyHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCompanyAction $action,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private CompanyTypeRepository $companyTypes,
        private DocumentTypeRepository $documentTypes,
        private AccessApi $access,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws CompanyNotFound|Unauthorized
     */
    public function handle(ViewCompany $query): StaffCompanyView
    {
        [$scope, $found] = $this->action->about(self::PERMISSION, $query->companyId);
        $this->authorizer->authorize(self::PERMISSION, $scope);

        return $this->db->transaction(function () use ($found): StaffCompanyView {
            $this->applications->lockAccountForReading($found->customerId());

            return $this->read($this->companies->forCustomer($found->customerId()) ?? throw new CompanyNotFound);
        });
    }

    private function read(Company $company): StaffCompanyView
    {
        $companyTypes = [];

        foreach ($this->companyTypes->all($company->homeStoreId()) as $type) {
            $companyTypes[$type->id()] = $type;
        }

        $documentTypes = [];

        foreach ($this->documentTypes->all($company->homeStoreId()) as $type) {
            $documentTypes[$type->id()] = $type;
        }

        $names = [];
        $details = $company->details();
        $customer = $this->access->customer($company->customerId());
        $applications = [];

        foreach ($this->applications->historyOf($company->id()) as $sent) {
            $applications[] = $this->sent($sent, $companyTypes, $documentTypes, $names);
        }

        return new StaffCompanyView(
            $company->id(),
            ApplicationViews::values($details->name->value, $details->type, $details->crNumber->value, $details->taxNumber->value, $details->address, null, $companyTypes),
            $company->status()->value,
            $company->statusBeforeSuspension()?->value,
            $company->statusReason()?->value,
            ApplicationViews::time($company->statusChangedAt()),
            $this->staffName($company->statusChangedBy(), $names),
            $company->homeStoreId(),
            $company->mayOrder(),
            $customer === null ? null : new HolderView(
                $customer->firstName, $customer->lastName, $customer->email, $customer->phone,
                $customer->emailVerified, $customer->phoneVerified, $customer->anonymized,
            ),
            $applications,
        );
    }

    /**
     * @param  array<string, CompanyType>  $companyTypes
     * @param  array<string, DocumentType>  $documentTypes
     * @param  array<string, string>  $names
     */
    private function sent(Application $sent, array $companyTypes, array $documentTypes, array &$names): StaffApplicationView
    {
        $typeId = $sent->type()?->typeId;
        $waiting = $sent->state() === ApplicationState::Submitted;

        return new StaffApplicationView(
            $sent->id(),
            $sent->state()->value,
            ApplicationViews::reference($sent),
            ApplicationViews::applicationValues($sent, $companyTypes),
            ApplicationViews::time($sent->submittedAt()),
            ApplicationViews::time($sent->decidedAt()),
            $this->staffName($sent->decidedBy(), $names),
            $sent->decisionReason()?->value,
            ApplicationViews::files($sent->documents(), $documentTypes, markInactive: false),
            ApplicationViews::flags($sent->flags()),
            ApplicationViews::requests($sent->requests()),
            ApplicationViews::answers($sent->answers()),
            $waiting && $typeId !== null && ($companyTypes[$typeId] ?? null)?->isActive() !== true,
        );
    }

    /**
     * @param  array<string, string>  $names  each staff member looked up once
     */
    private function staffName(?string $staffId, array &$names): ?string
    {
        if ($staffId === null) {
            return null;
        }

        if (! isset($names[$staffId])) {
            $staff = $this->access->staff($staffId);
            $names[$staffId] = $staff === null ? '' : trim($staff->firstName.' '.$staff->lastName);
        }

        return $names[$staffId] === '' ? null : $names[$staffId];
    }
}
