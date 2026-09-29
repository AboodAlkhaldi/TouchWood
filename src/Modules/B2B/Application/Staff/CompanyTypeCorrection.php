<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Staff;

use Modules\B2B\Application\Audit\StaffCompanyAudit;
use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\TypeListsNotice;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A staff member changing a company's type (b2b.md §1.3, §3.2) — the one way it is done, whichever
 * use case asks: a correction, an approval's choice, or the replacement of a deactivated type. It
 * changes the company, never an application it sent, and sends nobody back to `PENDING`.
 *
 * - **A suspended company's type is never changed** (amendment 10(h)): the domain refuses.
 * - **A deactivated type** is taken only once staff confirm it becomes active again, and only by
 *   someone who may also deactivate and activate that store's company types (amendments 8(b), 10(b));
 *   it is activated, then assigned. Keeping the old type for this company alone on an approval
 *   (10(e)) is the one way to hold a deactivated type without activating it.
 * - **An open draft follows** only if its type is still the one the company had (§3.1).
 *
 * Runs inside the caller's transaction, after its locks: the store's type-list lock first when a
 * type may be activated, then the account's, then the company's row — B2B's one order (README).
 */
final readonly class CompanyTypeCorrection
{
    public function __construct(
        private Authorizer $authorizer,
        private CompanyRepository $companies,
        private CompanyTypeRepository $companyTypes,
        private ApplicationRepository $applications,
        private TypeListsNotice $notice,
        private PlatformApi $platform,
    ) {}

    /**
     * What staff chose: exactly one of a listed type's id or "Other" in words.
     *
     * @throws InvalidCompanyAttribute
     */
    public static function choice(?string $typeId, ?string $other): CompanyTypeChoice
    {
        $typeId = $typeId === null || trim($typeId) === '' ? null : trim($typeId);
        $other = $other === null || trim($other) === '' ? null : $other;

        return match (true) {
            $typeId !== null && $other === null => CompanyTypeChoice::listed($typeId),
            $typeId === null && $other !== null => CompanyTypeChoice::other($other),
            default => throw new InvalidCompanyAttribute('company_type', 'a listed type or Other, exactly one'),
        };
    }

    /**
     * @param  Company  $company  read under the account's lock and locked itself
     * @param  bool  $keepInactive  the approval's "keep the old type for this company alone"
     *
     * @throws CompanySuspended|CompanyTypeInactive|InvalidCompanyAttribute|Unauthorized
     */
    public function apply(Company $company, CompanyTypeChoice $to, string $action, PermissionScope $scope, bool $confirmReactivation = false, bool $keepInactive = false): void
    {
        $types = $this->companyTypes->all($company->homeStoreId());
        $from = $company->details()->type;

        // Suspended and not-the-home-store's are refused here, before anything else is touched.
        $company->correctType($to, $types);

        if ($to->typeId !== null && ! $keepInactive) {
            foreach ($types as $type) {
                if ($type->id() !== $to->typeId || $type->isActive()) {
                    continue;
                }

                if (! $confirmReactivation) {
                    throw new CompanyTypeInactive;
                }

                $this->authorizer->authorize(B2BPermissions::COMPANY_TYPE_DEACTIVATE, $scope);

                $was = $type->inactiveDisplay();
                $type->activate();
                $this->companyTypes->update($type);
                $this->notice->clear($type->storeId());

                if ($was !== null) {
                    $this->platform->recordAudit(TypeAudit::activated($type, $was));
                }
            }
        }

        if ($company->pullChanges() === []) {
            return;
        }

        $this->companies->update($company);
        $this->platform->recordAudit(StaffCompanyAudit::typeChanged($action, $company, $from));

        $draft = $this->applications->openFor($company->customerId());

        if ($draft !== null && $draft->state() === ApplicationState::Draft && $draft->type()?->equals($from) === true) {
            $draft->describe($draft->name(), $to, $draft->crNumber(), $draft->taxNumber(), $draft->address(), $draft->note());
            $this->applications->update($draft);
        }
    }
}
