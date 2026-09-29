<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Enums\AccountType;

/**
 * The pages other modules add to a customer's account (access.md amendment 50), registered in their
 * service providers as admin menu entries are. Access lists them beside the account's own tabs; it
 * never knows what is on them.
 *
 * Offering a page is not allowing it: every page behind an entry checks, in its own handler, that
 * the account may use it (handoff §19).
 */
interface CustomerAccountPages
{
    /**
     * @throws \LogicException for two pages with the same module and key, or two claiming one route
     */
    public function register(CustomerAccountPageDto ...$pages): void;

    /**
     * The pages offered to an account of this type, lowest position first.
     *
     * @return list<CustomerAccountPageDto>
     */
    public function for(AccountType $type): array;
}
