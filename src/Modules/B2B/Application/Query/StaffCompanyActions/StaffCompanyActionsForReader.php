<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\StaffCompanyActions;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Public\Enums\CompanyStatus;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * What the staff screens may offer (b2b.md §4.6, amendment 21): which buttons a company's page draws
 * for the person acting now, and which stores the company list may be filtered by.
 *
 * The answer is **B2B's, not the screen's** — a controller that asked the authorizer itself would be
 * deciding, and a test forbids it (tests/Architecture/AccessDecisionsTest) — and every handler asks
 * again anyway: offering is never allowing (handoff §19). As Access's CustomerActionsForReader, each
 * job is asked in **the company's home store** (§3.2), and only what can happen next is offered.
 *
 * It reads; it never locks. A state that changes between this answer and a press is the handler's to
 * refuse, in its own words.
 */
final readonly class StaffCompanyActionsForReader
{
    public function __construct(
        private Authorizer $authorizer,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private CompanyTypeRepository $companyTypes,
        private AccessApi $access,
    ) {}

    /**
     * The stores the company list may be filtered by: those the reader may view companies in. Null
     * for someone who may in every store, now and for any store opened later.
     *
     * @return list<string>|null
     */
    public function listStores(): ?array
    {
        $stores = $this->authorizer->storesWith(B2BPermissions::COMPANY_VIEW);

        return $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);
    }

    public function forCompany(string $companyId): StaffCompanyActions
    {
        $company = $this->companies->find($companyId);

        if ($company === null) {
            return StaffCompanyActions::none();
        }

        $home = $company->homeStoreId();
        $status = $company->status();
        $suspended = $status === CompanyStatus::Suspended;

        $review = $this->mayIn(B2BPermissions::COMPANY_REVIEW, $home);
        $suspend = $this->mayIn(B2BPermissions::COMPANY_SUSPEND, $home);
        $correct = $this->mayIn(B2BPermissions::COMPANY_CORRECT_TYPE, $home) && ! $suspended;

        // Only a PENDING company has something to decide (§4.1): the application it sent, waiting.
        $waiting = $status === CompanyStatus::Pending
            && $this->applications->lastSent($company->id())?->state() === ApplicationState::Submitted;
        $decide = $review && $waiting;

        return new StaffCompanyActions(
            mayOpenDocuments: $this->mayIn(B2BPermissions::COMPANY_DOCUMENT_VIEW, $home),
            mayApprove: $decide,
            approveRefusal: $decide ? $this->approveRefusal($company->details()->type->isOther(), $company->customerId()) : null,
            mayReject: $decide,
            maySuspend: $suspend && ! $suspended,
            mayReinstate: $suspend && $suspended,
            mayCorrectType: $correct,
            // An approved company is never corrected to "Other" (amendment 13(b)).
            mayChooseOther: $correct && $status !== CompanyStatus::Approved,
            typeChoices: $correct ? $this->choices($home, $this->mayIn(B2BPermissions::COMPANY_TYPE_DEACTIVATE, $home)) : [],
        );
    }

    /**
     * Why approving would be refused now — said beside a disabled button rather than after a press.
     */
    private function approveRefusal(bool $other, string $customerId): ?string
    {
        if ($other) {
            return StaffCompanyActions::APPROVE_TYPE_NOT_SET;
        }

        return $this->access->customer($customerId)?->anonymized === true ? StaffCompanyActions::APPROVE_ACCOUNT_DELETED : null;
    }

    /**
     * The home store's company types, in the form's order. A deactivated one only for someone who
     * may activate it again: choosing it activates it first (amendments 8(b), 10(b)).
     *
     * @return list<CorrectionChoice>
     */
    private function choices(string $storeId, bool $mayActivate): array
    {
        $choices = [];

        foreach ($this->companyTypes->all($storeId) as $type) {
            if ($type->isActive() || $mayActivate) {
                $choices[] = self::choice($type);
            }
        }

        return $choices;
    }

    private static function choice(CompanyType $type): CorrectionChoice
    {
        return new CorrectionChoice($type->id(), $type->name()->ar, $type->name()->en, $type->isActive());
    }

    /**
     * Whether the reader holds a job in one store; null from storesWith() is every store.
     */
    private function mayIn(string $permission, string $storeId): bool
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === null) {
            return true;
        }

        foreach ($stores as $store) {
            if ($store->value === $storeId) {
                return true;
            }
        }

        return false;
    }
}
