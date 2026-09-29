<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\StartApplicationDraft;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Domain\Exception\ApplicationAlreadyOpen;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Public\Enums\CompanyStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The account's first draft, empty; or a later one, from the company as it is now plus the files
 * of the last application it sent (b2b.md §1.2, §3.1, amendments 4 and 5).
 *
 * - A draft already open is returned, not started again: the account has one open application.
 * - A sent application still waiting refuses it (ApplicationAlreadyOpen).
 * - A suspended company starts nothing (CompanySuspended); it may only discard a draft it has.
 *
 * Starting a draft is not audited (amendment 4): only sending and discarding one are.
 */
final readonly class StartApplicationDraftHandler
{
    public const string PERMISSION = B2BPermissions::APPLY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private ApplicationRepository $applications,
        private CompanyRepository $companies,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return string the id of the account's open draft
     *
     * @throws NotACompanyAccount|CompanySuspended|ApplicationAlreadyOpen
     */
    public function handle(StartApplicationDraft $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->account->get(self::PERMISSION)->id;

        return $this->db->transaction(function () use ($customerId): string {
            $this->applications->lockAccount($customerId);
            $company = $this->companies->forCustomerLocked($customerId);

            if ($company?->status() === CompanyStatus::Suspended) {
                throw new CompanySuspended;
            }

            $open = $this->applications->openFor($customerId);

            if ($open !== null) {
                return $open->state() === ApplicationState::Draft ? $open->id() : throw new ApplicationAlreadyOpen;
            }

            $draft = $company === null
                ? Application::draft($this->applications->nextId(), $customerId, null)
                : Application::draftFor($this->applications->nextId(), $company, $this->applications->lastSent($company->id()));

            $this->applications->add($draft);

            return $draft->id();
        }, 3);
    }
}
