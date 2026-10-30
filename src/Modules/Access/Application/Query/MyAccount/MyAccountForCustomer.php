<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAccount;

use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\CustomerReader;
use Modules\Access\Application\Security\ServedPhones;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Repository\CustomerRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * What a customer's own account pages show them (frontend.md §3.6).
 *
 * It answers about the person asking and nobody else: the id comes from who is signed in, so there
 * is no way to spell somebody else's account here. The permission is the one every customer holds
 * for their own account, asked for here so the read and the things the pages can change agree
 * about who may be there at all.
 *
 * Read from the domain model rather than from {@see CustomerReader},
 * which is the **staff** screens' read side: what a customer may see about themselves and what an
 * administrator may see about them are two questions, and answering both from one place is how
 * they come to drift.
 */
final readonly class MyAccountForCustomer
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private ServedPhones $phones,
    ) {}

    /**
     * @throws CustomerNotFound
     */
    public function forCurrentCustomer(): MyCustomerAccountDto
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->current->id(self::PERMISSION);
        $customer = $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);
        // A saved number whose country left the SMS countries list (amendment 87): still verified, but
        // no code reaches it, and ordering waits until they change it.
        $phoneCountryUnlisted = $customer->phone() !== null && ! $this->phones->reaches($customer->phone());

        return new MyCustomerAccountDto(
            id: $customer->id(),
            email: $customer->email()->value,
            firstName: $customer->firstName(),
            lastName: $customer->lastName(),
            accountType: $customer->accountType(),
            locale: $customer->language()->value,
            phone: $customer->phone()?->value,
            emailVerified: $customer->emailVerifiedAt() !== null,
            phoneVerified: $customer->phoneVerifiedAt() !== null,
            phoneCountryUnlisted: $phoneCountryUnlisted,
            // The domain's own answer, never this page's arithmetic: what ordering waits for is
            // Access's rule and it may grow (§1.2) - with the SMS countries' part, as
            // AccessApi::customerMayOrder answers Sales (amendment 87).
            mayOrder: $customer->mayOrder() && ! $phoneCountryUnlisted,
            homeStoreId: $customer->homeStoreId(),
            deletionScheduledFor: $customer->deletionScheduledFor()?->format('Y-m-d'),
            marketingConsent: $customer->marketingConsent(),
        );
    }
}
