<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use Modules\Access\Domain\Exception\EmailAlreadyRegistered;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Model\Customer;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\PhoneNumber;

interface CustomerRepository
{
    public function nextId(): string;

    /**
     * Locks the row until the transaction ends, so two changes to one account queue up.
     */
    public function byId(string $id): ?Customer;

    /**
     * A read with no lock, for answers to other modules.
     */
    public function find(string $id): ?Customer;

    /**
     * The customer with this email, ignoring case. Locks the row.
     */
    public function byEmail(EmailAddress $email): ?Customer;

    /**
     * Whether a customer account already has this email, ignoring case (spec §1.2).
     */
    public function emailInUse(EmailAddress $email): bool;

    /**
     * Whether another customer uses this phone; it is unique across customers (spec §1.1). A staff
     * member may use the same number: each account verifies it once (amendment 13).
     */
    public function phoneInUse(PhoneNumber $phone, ?string $exceptCustomerId = null): bool;

    /**
     * @throws EmailAlreadyRegistered|PhoneAlreadyInUse when the database refuses the value the code
     *                                                  checked first (someone took it in between)
     */
    public function add(Customer $customer): void;

    /**
     * @throws PhoneAlreadyInUse
     */
    public function update(Customer $customer): void;
}
