<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Points;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * What every staff change to a customer's points shares (loyalty.md §1.9, §3, §7), as B2B's
 * `StaffCompanyAction` does for companies: the permission is checked in **the store the points
 * belong to**. Someone who holds the job in no store at all is refused plainly, by its name; someone
 * who holds it, but not in this store, is told exactly what they would be told for a customer with
 * no points there — `PointsAccountNotFound` — so the panel never confirms whose points exist.
 */
final readonly class StaffPointsAction
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private AccessApi $access,
    ) {}

    /**
     * @throws PointsAccountNotFound|Unauthorized
     */
    public function scope(string $permission, StoreId $store): PermissionScope
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === []) {
            throw new Unauthorized($permission);
        }

        // null is what storesWith() answers for every store, now and for one opened later.
        if ($stores !== null && ! self::covers($stores, $store)) {
            throw new PointsAccountNotFound;
        }

        return PermissionScope::store($store);
    }

    /**
     * The staff member making the change: a change by hand is recorded against one (`staff_id`), so
     * only a staff member may make it — not the system, which the permission check lets through.
     *
     * @throws Unauthorized
     */
    public function staffId(string $permission): string
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        return strtolower($actor->id);
    }

    /**
     * The customer, lowercased; one that does not exist has no points to change — the same answer as
     * any other. An anonymized customer's points stay as they are (§1.11): nothing here treats them
     * apart.
     *
     * @throws PointsAccountNotFound
     */
    public function customer(string $customerId): string
    {
        return strtolower($this->existingCustomer($customerId)->id);
    }

    /**
     * The same, answering the customer itself.
     *
     * @throws PointsAccountNotFound
     */
    public function existingCustomer(string $customerId): CustomerDto
    {
        $customer = Ulids::valid($customerId) ? $this->access->customer($customerId) : null;

        if ($customer === null) {
            throw new PointsAccountNotFound;
        }

        return $customer;
    }

    /**
     * Whether the staff member looking may open this customer's own page, as Access answers it
     * (access.md amendments 44(b), 72(e)): a customer of a store they see. Who may read a customer
     * with no points in a store, and their email (loyalty.md §4.1, §9.5 P1, P7).
     */
    public function mayViewCustomer(CustomerDto $customer): bool
    {
        $stores = $this->access->customerViewStores();

        return $stores === null || self::covers($stores, StoreId::fromString($customer->homeStoreId));
    }

    /**
     * Whether the staff member looking holds this job in this store - for what a screen offers, never
     * for what a handler allows: each handler still asks (handoff §19).
     */
    public function holds(string $permission, StoreId $store): bool
    {
        $stores = $this->authorizer->storesWith($permission);

        return $stores === null || self::covers($stores, $store);
    }

    /**
     * @param  list<StoreId>  $stores
     */
    private static function covers(array $stores, StoreId $store): bool
    {
        foreach ($stores as $held) {
            if ($held->equals($store)) {
                return true;
            }
        }

        return false;
    }
}
