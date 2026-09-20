<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewCustomer;

/**
 * One customer, for a staff member who may see them (spec §3.3, amendment 43): the account, their
 * contacts and their addresses.
 */
final readonly class ViewCustomer
{
    public function __construct(
        public string $customerId,
    ) {}
}
