<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\CustomerActions;

use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Enums\CustomerStatus;
use Shared\Application\Authorizer;

/**
 * What the person acting now may do to one customer (frontend.md §3.7, G2).
 *
 * The screen asks once and offers only what comes back, so nobody is shown a button that refuses
 * them when pressed. The answer is **Access's, not the screen's** - a controller that asked the
 * authorizer itself would be deciding, and a test forbids it (tests/Architecture) - and each
 * handler asks again anyway: offering is never allowing (handoff §19).
 *
 * Both permissions are per store, and the store that matters is the customer's home store: it is
 * the one that decides who may see them at all (spec §3.3).
 *
 * Two rules sit above the permissions:
 *
 *   - **An anonymized account is past all of this.** There is nothing left to block or to close.
 *   - **Only what can happen next is offered**: an account is blocked or unblocked, never both,
 *     and a closing is started or stopped, never started twice.
 */
final readonly class CustomerActionsForReader
{
    public function __construct(
        private Authorizer $authorizer,
        private CustomerRepository $customers,
    ) {}

    public function forCustomer(string $customerId): CustomerActionsDto
    {
        $customer = $this->customers->byId($customerId);

        if ($customer === null || $customer->isAnonymized()) {
            return CustomerActionsDto::none();
        }

        $home = $customer->homeStoreId();
        $mayBlock = $this->mayIn(AccessPermissions::CUSTOMER_BLOCK, $home);
        $mayDelete = $this->mayIn(AccessPermissions::CUSTOMER_DELETE, $home);

        $blocked = $customer->status() === CustomerStatus::Blocked;
        $closing = $customer->deletionScheduledFor() !== null;

        return new CustomerActionsDto(
            mayBlock: $mayBlock && ! $blocked,
            mayUnblock: $mayBlock && $blocked,
            mayStartDeletion: $mayDelete && ! $closing,
            // For a customer who cannot sign in to stop it themselves, which is the only other way.
            mayCancelDeletion: $mayDelete && $closing,
        );
    }

    /**
     * Whether the reader holds a permission in one particular store.
     *
     * `storesWith` answers null for somebody limited by nothing at all - a Super Admin - which is
     * every store, including one opened tomorrow.
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
