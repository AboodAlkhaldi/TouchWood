<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListCustomers;

use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;

/**
 * One row of the customer list (spec §3.3). An anonymized account keeps its place — orders and
 * reviews still point at it — under the name every screen shows for one.
 */
final readonly class CustomerSummary
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public CustomerStatus $status,
        public AccountType $accountType,
        public string $homeStoreId,
        public bool $emailVerified,
        public bool $phoneVerified,
        public ?string $deletionScheduledFor,
        public bool $anonymized,
        public string $registeredAt,
    ) {}
}
