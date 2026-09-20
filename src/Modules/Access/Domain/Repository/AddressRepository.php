<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use Modules\Access\Domain\Model\Address;

/**
 * A customer's addresses (spec §1.9). Everything that changes which address is the default runs
 * inside one transaction, so a store never has two defaults or, while it has an address, none.
 */
interface AddressRepository
{
    public function nextId(): string;

    /** A read with no lock, for answers to other modules. */
    public function find(string $addressId): ?Address;

    /** Locks the row: for a change, inside its transaction. */
    public function byId(string $addressId): ?Address;

    /**
     * The customer's addresses in one store, the default first, then the newest.
     *
     * @return list<Address>
     */
    public function forCustomerInStore(string $customerId, string $storeId): array;

    public function countForCustomerInStore(string $customerId, string $storeId): int;

    /** The newest address the customer has in that store, apart from $exceptId. */
    public function newestInStore(string $customerId, string $storeId, string $exceptId): ?Address;

    public function add(Address $address): void;

    public function update(Address $address): void;

    public function delete(string $addressId): void;

    /** Takes the default flag off every other address of that customer in that store. */
    public function clearDefault(string $customerId, string $storeId, string $exceptId): void;
}
