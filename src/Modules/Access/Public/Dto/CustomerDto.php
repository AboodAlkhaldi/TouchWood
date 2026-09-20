<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;

/**
 * A customer, as other modules see them (Access spec §2.4). Sales asks whether they may order
 * (`AccessApi::customerMayOrder`), B2B reads the account type, Ops sends in their language.
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
        public ?string $deletionScheduledFor = null,
    ) {}
}
