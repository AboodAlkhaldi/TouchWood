<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Draft;

use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * **The draft's actions name no application** (b2b.md §3.1, amendment 5): they act on the account's
 * one open application. What every one of them does first, inside its own transaction.
 *
 * One lock order for all of them (lesson 37): the account, then the company's row, then the
 * application — so a staff member suspending the company waits for a save in progress, and the save
 * never acts on a company suspended under it.
 */
final readonly class OpenDrafts
{
    public function __construct(
        private ApplicationRepository $applications,
        private CompanyRepository $companies,
    ) {}

    /**
     * Refused, in this order: no open application (ApplicationNotFound); the company is suspended
     * (CompanySuspended, amendments 5 and 9(a)) — discarding is the one thing a suspended company's
     * draft allows, and it does not come through here; the open application was already sent
     * (ApplicationNotEditable).
     *
     * @throws ApplicationNotFound|CompanySuspended|ApplicationNotEditable
     */
    public function forChange(string $customerId): DraftInHand
    {
        $this->applications->lockAccount($customerId);
        $company = $this->companies->forCustomerLocked($customerId);
        $draft = $this->applications->openFor($customerId) ?? throw new ApplicationNotFound;

        if ($company?->status() === CompanyStatus::Suspended) {
            throw new CompanySuspended;
        }

        if ($draft->state() !== ApplicationState::Draft) {
            throw new ApplicationNotEditable($draft->state()->value);
        }

        return new DraftInHand($draft, $company);
    }
}
