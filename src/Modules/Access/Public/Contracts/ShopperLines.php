<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\ShopperLineDto;
use Modules\Access\Public\Enums\AccountType;

/**
 * The lines modules put under the shop's header for a signed-in customer (access.md amendment 50),
 * registered in their service providers. Access draws them; it never knows what they say.
 */
interface ShopperLines
{
    /**
     * @param  class-string  $line  a class implementing ShopperLine, resolved from the container
     *                              each time it is asked, so it may depend on the request
     *
     * @throws \LogicException for a class that is not a ShopperLine, or one registered twice
     */
    public function register(string $line): void;

    /**
     * The lines owed to this customer, in the order they were registered.
     *
     * @return list<ShopperLineDto>
     */
    public function for(string $customerId, AccountType $accountType, bool $emailVerified): array;
}
