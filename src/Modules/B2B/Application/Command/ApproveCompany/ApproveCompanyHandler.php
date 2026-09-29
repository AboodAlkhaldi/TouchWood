<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ApproveCompany;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\B2B\Application\Audit\StaffCompanyAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Staff\CompanyMessages;
use Modules\B2B\Application\Staff\CompanyTypeCorrection;
use Modules\B2B\Application\Staff\StaffCompanyAction;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Exception\CompanyTypeChoiceNotNeeded;
use Modules\B2B\Domain\Exception\CompanyTypeChoiceRequired;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **`ApproveCompany`** (b2b.md §3.2, §4.1): decides the application a `PENDING` company sent — the
 * company is `APPROVED` and may order. An optional note goes to the customer with the approval email
 * (amendment 1), sent once the approval has committed.
 *
 * **When the application's company type was deactivated after it was sent** (§1.3, amendment 10(e)),
 * the reviewer must say which type the company keeps — `CompanyTypeChoiceRequired` otherwise, with
 * nothing written: the replacement the deactivation gave it, the sent type for this company alone,
 * or a correction (which also needs `b2b.company.correct_type`). A choice sent when none is needed —
 * the type is active again — is refused, `CompanyTypeChoiceNotNeeded` (10(i)), so the reviewer looks
 * again.
 *
 * Audited on the application, as sending is; a type change is audited on the company besides.
 */
final readonly class ApproveCompanyHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_REVIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCompanyAction $action,
        private CompanyTypeCorrection $correction,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private CompanyTypeRepository $companyTypes,
        private StoreTypeListsRepository $lists,
        private CompanyMessages $messages,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws CompanyNotFound|CompanyTypeChoiceNotNeeded|CompanyTypeChoiceRequired|CompanyTypeInactive
     * @throws InvalidCompanyAttribute|InvalidCompanyStatus|Unauthorized
     */
    public function handle(ApproveCompany $command): void
    {
        [$scope, $found] = $this->action->about(self::PERMISSION, $command->companyId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $staffId = $this->action->decider(self::PERMISSION);
        $note = $command->note === null || trim($command->note) === '' ? null : Remark::of('note', $command->note);
        $corrected = null;

        if ($command->typeChoice === ApprovalTypeChoice::Correct) {
            $this->authorizer->authorize(B2BPermissions::COMPANY_CORRECT_TYPE, $scope);
            $corrected = CompanyTypeCorrection::choice($command->correctTypeId, $command->correctOther);
        }

        $this->db->transaction(function () use ($found, $staffId, $note, $command, $corrected, $scope): void {
            // B2B's one lock order: the store's lists first, when a correction may activate a type.
            if ($corrected?->typeId !== null) {
                $this->lists->lockLists($found->homeStoreId());
            }

            $this->applications->lockAccount($found->customerId());
            $company = $this->companies->forCustomerLocked($found->customerId()) ?? throw new CompanyNotFound;
            $waiting = $this->applications->openFor($company->customerId());

            // Only a PENDING company has something to decide (§4.1); asked first, so a suspended
            // company's waiting application is refused for what it is, not for its type.
            if ($company->status() !== CompanyStatus::Pending || $waiting?->state() !== ApplicationState::Submitted) {
                throw new InvalidCompanyStatus('approved', $company->status()->value);
            }

            $this->settleType($company, $waiting->type(), $command, $corrected, $scope);

            $now = CarbonImmutable::now();
            $waiting->approve($staffId, $note, $now);
            $company->approve($staffId, $now);

            $this->applications->update($waiting);
            $this->companies->update($company);
            $this->platform->recordAudit(StaffCompanyAudit::approved($waiting, $note, $command->typeChoice?->value, $company->homeStoreId()));
            $this->messages->afterCommit($company->customerId(), static fn (SecurityMessages $messages, CustomerDto $customer) => $messages->companyApproved($customer, $note?->value));
        }, 3);
    }

    /**
     * @throws CompanyTypeChoiceNotNeeded|CompanyTypeChoiceRequired|CompanyTypeInactive|InvalidCompanyAttribute|Unauthorized
     */
    private function settleType(Company $company, ?CompanyTypeChoice $sent, ApproveCompany $command, ?CompanyTypeChoice $corrected, PermissionScope $scope): void
    {
        $stale = $sent !== null && $sent->typeId !== null && ! $this->isActive($company->homeStoreId(), $sent->typeId);

        if (! $stale) {
            if ($command->typeChoice !== null) {
                throw new CompanyTypeChoiceNotNeeded;
            }

            return;
        }

        if ($command->typeChoice === null) {
            throw new CompanyTypeChoiceRequired;
        }

        if ($command->typeChoice === ApprovalTypeChoice::Replacement) {
            // Nothing to write: the deactivation already gave the company its replacement — unless
            // staff left the companies with the old type, when there is none to use.
            if ($company->details()->type->equals($sent)) {
                throw new InvalidCompanyAttribute('type_choice', 'no replacement was set for this company');
            }

            return;
        }

        if ($command->typeChoice === ApprovalTypeChoice::Keep) {
            $this->correction->apply($company, $sent, 'b2b.company.type_corrected', $scope, keepInactive: true);

            return;
        }

        $this->correction->apply($company, $corrected ?? $sent, 'b2b.company.type_corrected', $scope, $command->confirmReactivation);
    }

    private function isActive(string $storeId, string $typeId): bool
    {
        $type = array_values(array_filter(
            $this->companyTypes->all($storeId),
            static fn (CompanyType $type): bool => $type->id() === $typeId,
        ))[0] ?? null;

        // A type the store no longer lists at all cannot be chosen again either.
        return $type?->isActive() === true;
    }
}
