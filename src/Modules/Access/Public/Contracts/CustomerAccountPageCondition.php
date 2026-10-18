<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

/**
 * What a module implements to offer its account page only some of the time (access.md amendment
 * 72(d)): Loyalty's Points tab while the store's programme is on or the customer has points there.
 *
 * It is asked on every shop page a signed-in customer opens - the account menu travels with each
 * one - so it answers from what the request already holds, and reads at most one row of its own.
 * The store is the shop's, from the request's store context.
 */
interface CustomerAccountPageCondition
{
    public function offers(string $customerId): bool;
}
