<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\ShopperLineDto;
use Modules\Access\Public\Enums\AccountType;

/**
 * What a module implements to say one thing to a signed-in customer on every page of the shop
 * (access.md amendment 50) — B2B telling a company account why it cannot order yet.
 *
 * It is asked on every shop page a signed-in customer opens, so it answers from what Access hands
 * it before reading anything of its own: most customers are owed no line at all.
 */
interface ShopperLine
{
    public function lineFor(string $customerId, AccountType $accountType, bool $emailVerified): ?ShopperLineDto;
}
