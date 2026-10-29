<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use Modules\Access\Application\Security\ServedPhones;
use Modules\Access\Domain\Model\Customer;
use Modules\Access\Public\Dto\CustomerDto;

/**
 * A customer as other modules see them (spec §2.4).
 *
 * A phone whose country was taken off the SMS countries list reads as unverified (amendment 87; owner,
 * 2026-10-29): no code can reach it, and the customer cannot order until they change it - Sales' cart
 * already stops at an unverified phone.
 */
final readonly class CustomerMapper
{
    public function __construct(
        private ServedPhones $phones,
    ) {}

    public function toDto(Customer $customer): CustomerDto
    {
        return new CustomerDto(
            $customer->id(),
            $customer->accountType(),
            $customer->status(),
            $customer->firstName(),
            $customer->lastName(),
            $customer->email()->value,
            $customer->phone()?->value,
            $customer->emailVerifiedAt() !== null,
            $customer->phoneVerifiedAt() !== null && $this->phones->reaches($customer->phone()),
            $customer->language()->value,
            $customer->homeStoreId(),
            $customer->deletionScheduledFor()?->format(DATE_ATOM),
            $customer->isAnonymized(),
            $customer->marketingConsent(),
        );
    }
}
