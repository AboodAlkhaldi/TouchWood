<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;

/**
 * A customer, as other modules see them (Access spec §2.4). Sales asks whether they may order
 * (`AccessApi::customerMayOrder`), B2B reads the account type and the home store, Ops sends in
 * their language.
 */
final readonly class CustomerDto
{
    public function __construct(
        public string $id,
        public AccountType $accountType,
        public CustomerStatus $status,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public bool $emailVerified,
        public bool $phoneVerified,
        public string $locale,
        /**
         * The store the account registered in, which never changes (spec §1.1): it decides which
         * staff see the customer, and which staff review their company (b2b.md §3.2). Not where
         * they may shop — that is every store.
         */
        public string $homeStoreId,
        public ?string $deletionScheduledFor = null,
        /** True once the fourteen days passed and the account was emptied: the name and email are placeholders. */
        public bool $anonymized = false,
    ) {}
}
