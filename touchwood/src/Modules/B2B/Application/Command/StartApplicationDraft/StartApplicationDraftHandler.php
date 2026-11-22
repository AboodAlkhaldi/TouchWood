<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\StartApplicationDraft;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Account\CarriedOver;
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
 * The account's first draft **in the store it is browsing**, empty — or starting with the name and
 * type of its company in another store (amendment 19(b)); or a later one, from the company as it is
 * now plus the files of the last application it sent (b2b.md §1.2, §3.1, amendments 4, 5 and 18).
 *
 * - A draft already open in this store is returned, not started again: the account has one open
 *   application per store.
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
        private CarriedOver $carriedOver,
    ) {}

    /**
     * @return string the id of the account's open draft
     *
     * @throws NotACompanyAccount|CompanySuspended|ApplicationAlreadyOpen
     */
    public function handle(StartApplicationDraft $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $account = $this->account->get(self::PERMISSION);
        $customerId = $account->id;
        // It applies in the store it is browsing (amendment 19(a)).
        $store = $this->account->store($account);

        return $this->db->transaction(function () use ($customerId, $store): string {
            $this->applications->lockAccount($customerId);
            $company = $this->companies->forCustomerLocked($customerId, $store);

            if ($company?->status() === CompanyStatus::Suspended) {
                throw new CompanySuspended;
            }

            $open = $this->applications->openFor($customerId, $store);

            if ($open !== null) {
                return $open->state() === ApplicationState::Draft ? $open->id() : throw new ApplicationAlreadyOpen;
            }

            $draft = $company === null
                ? $this->first($customerId, $store)
                : Application::draftFor($this->applications->nextId(), $company, $this->applications->lastSent($company->id()));

            $this->applications->add($draft);

            return $draft->id();
        }, 3);
    }

    /**
     * The account's first draft in this store: empty, or — when it has a company in another store —
     * starting with that company's name and type (amendment 19(b), CarriedOver).
     */
    private function first(string $customerId, string $store): Application
    {
        $draft = Application::draft($this->applications->nextId(), $customerId, null, $store);
        // No company here (the caller found none), so any the account holds is in another store.
        $source = $this->carriedOver->source($customerId);

        if ($source !== null) {
            $draft->describe($this->carriedOver->name($source), $this->carriedOver->type($source, $store), null, null, null, null);
        }

        return $draft;
    }
}
