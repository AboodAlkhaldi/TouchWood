<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Account;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\StoreContext;
use Shared\Application\Unauthorized;

/**
 * The company account this request acts as (b2b.md §3.1). Every one of the company's own use cases
 * starts here: the account is always the signed-in one, never an id from the request, so nobody
 * reaches another account's application by sending its id.
 */
final readonly class CurrentCompanyAccount
{
    public function __construct(
        private ActorContext $actors,
        private AccessApi $access,
        private StoreContext $stores,
    ) {}

    /**
     * The store the account is applying in: **the store it is browsing** (b2b.md amendment 19(a)) —
     * every company page sits under a store. Outside a storefront request (the console, a test), the
     * account's home store (amendment 20(e)).
     */
    public function store(CustomerDto $account): string
    {
        return $this->stores->has() ? $this->stores->current()->value : strtolower($account->homeStoreId);
    }

    /**
     * @param  string  $permission  the one the caller was about to check, so a refusal names the
     *                              action that was refused
     *
     * @throws Unauthorized when a guest, a staff member or the system is acting
     * @throws NotACompanyAccount an individual account (§3.1), or one Access cannot find (amendment 9(b))
     */
    public function get(string $permission): CustomerDto
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        $customer = $this->access->customer($actor->id);

        // An account type never changes, so this is a fact about the account, checked where it
        // cannot be walked around rather than only by a screen that does not offer the link.
        if ($customer === null || $customer->accountType !== AccountType::Company) {
            throw new NotACompanyAccount;
        }

        return $customer;
    }
}
