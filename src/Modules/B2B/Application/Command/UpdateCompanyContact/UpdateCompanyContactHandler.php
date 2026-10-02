<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\UpdateCompanyContact;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\Account\SavedAddresses;
use Modules\B2B\Application\Audit\CompanyAccountAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * **The address changes freely** (b2b.md §1.1), and it never sends the company back to `PENDING` —
 * it is not one of the details staff approve — **but not while the company is suspended**
 * (CompanySuspended, amendment 9(d)): a suspended company changes nothing, so nothing is ever written
 * into its frozen draft either.
 *
 * - Before the first application is sent there is no company, and the address lives in the draft
 *   (CompanyNotFound here).
 * - **While a draft is open, the change is written into it too** (amendment 5), so the next
 *   application does not send the old one.
 *
 * The address is **one of the account's saved addresses**, kept as a copy (amendment 16(f),
 * SavedAddresses::pick): another account's, or one its store's format no longer accepts, is refused.
 *
 * Audited on the company, the address as "changed" (amendment 4); picking the address already
 * picked changes nothing and writes nothing.
 */
final readonly class UpdateCompanyContactHandler
{
    public const string PERMISSION = B2BPermissions::UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private SavedAddresses $addresses,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NotACompanyAccount|InvalidCompanyAttribute|CompanyNotFound|CompanySuspended
     */
    public function handle(UpdateCompanyContact $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $account = $this->account->get(self::PERMISSION);
        $customerId = $account->id;
        // The company of the store being browsed (amendment 19(c)).
        $store = $this->account->store($account);

        $this->db->transaction(function () use ($customerId, $store, $command): void {
            $this->applications->lockAccount($customerId);
            $company = $this->companies->forCustomerLocked($customerId, $store) ?? throw new CompanyNotFound;
            $address = $this->addresses->pick($customerId, $command->addressId);

            $company->moveTo($address);

            if ($company->pullChanges() === []) {
                return;
            }

            $this->companies->update($company);
            $this->platform->recordAudit(CompanyAccountAudit::addressChanged($company));

            $draft = $this->applications->openFor($customerId, $store);

            if ($draft !== null && $draft->state() === ApplicationState::Draft) {
                $draft->describe($draft->name(), $draft->type(), $draft->crNumber(), $draft->taxNumber(), $address, $draft->note());
                $this->applications->update($draft);
            }
        }, 3);
    }
}
