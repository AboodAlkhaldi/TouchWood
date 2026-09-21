<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use Modules\Access\Domain\Model\Customer;
use Modules\Access\Public\Dto\CustomerDto;

/**
 * A customer as other modules see them (spec §2.4).
 */
final readonly class CustomerMapper
{
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
            $customer->phoneVerifiedAt() !== null,
            $customer->language()->value,
            $customer->deletionScheduledFor()?->format(DATE_ATOM),
            $customer->isAnonymized(),
        );
    }
}
