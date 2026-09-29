<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\DeactivateCompanyType;

use Modules\B2B\Application\Audit\TypeAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Staff\CompanyTypeCorrection;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Public\Enums\CompanyStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **`DeactivateCompanyType`** (b2b.md §1.3, §3.2, amendments 5 and 10): no new application may choose
 * it, and it shows to one hidden or greyed out. Deactivating one already inactive changes only how it
 * shows.
 *
 * **Replacing it** moves every company holding it to another active type of the same store — approved
 * ones included — as a staff correction: the company changes, never an application it sent, nobody
 * goes back to `PENDING`, each company is audited, and an open draft follows only if it still held
 * the company's type (§3.1). **A suspended company is skipped** and keeps the old type (10(h)). An
 * application sent and still waiting keeps what it sent; its reviewer chooses when approving (10(e)).
 *
 * All in one transaction, in B2B's one lock order: the store's lists, then each account in turn, in
 * account order, then its company.
 */
final readonly class DeactivateCompanyTypeHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_TYPE_DEACTIVATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
        private CompanyTypeCorrection $correction,
        private CompanyTypeRepository $types,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
    ) {}

    /**
     * @throws CompanyTypeInactive|InvalidCompanyAttribute|TypeNotFound|Unauthorized
     */
    public function handle(DeactivateCompanyType $command): void
    {
        [$scope, $found] = $this->action->companyType(self::PERMISSION, $command->typeId);
        $this->authorizer->authorize(self::PERMISSION, $scope);

        $this->action->change($found->storeId(), function () use ($found, $command, $scope): array {
            $type = $this->types->byId($found->id()) ?? throw new TypeNotFound($found->id());
            $replacement = $command->replacementTypeId === null ? null : $this->replacement($type, $command->replacementTypeId);
            $wasActive = $type->isActive();
            $wasShown = $type->inactiveDisplay();

            $type->deactivate($command->shown);
            $changed = $type->pullChanges() !== [];

            if ($changed) {
                $this->types->update($type);
            }

            if ($replacement !== null) {
                $this->replace($type, $replacement, $scope);
            }

            return $changed || $replacement !== null ? [TypeAudit::deactivated($type, $wasActive, $wasShown, $replacement?->id())] : [];
        });
    }

    /**
     * @throws CompanyTypeInactive|InvalidCompanyAttribute
     */
    private function replacement(CompanyType $type, string $replacementTypeId): CompanyType
    {
        $replacement = $this->types->find($replacementTypeId);

        // Unknown, another store's, or itself: none is a replacement for it (§7).
        if ($replacement === null || $replacement->storeId() !== $type->storeId() || $replacement->id() === $type->id()) {
            throw new InvalidCompanyAttribute('replacement', 'another type of the same store');
        }

        if (! $replacement->isActive()) {
            throw new CompanyTypeInactive;
        }

        return $replacement;
    }

    private function replace(CompanyType $type, CompanyType $replacement, PermissionScope $scope): void
    {
        $held = CompanyTypeChoice::listed($type->id());

        foreach ($this->companies->holdersOf($type->id()) as $customerId) {
            $this->applications->lockAccount($customerId);
            $company = $this->companies->forCustomerLocked($customerId);

            // Read again under its locks: it may have moved since the list was read, and a suspended
            // company changes nothing, not even by staff (10(h)).
            if ($company === null || $company->status() === CompanyStatus::Suspended || ! $company->details()->type->equals($held)) {
                continue;
            }

            $this->correction->apply($company, CompanyTypeChoice::listed($replacement->id()), 'b2b.company.type_replaced', $scope);
        }
    }
}
