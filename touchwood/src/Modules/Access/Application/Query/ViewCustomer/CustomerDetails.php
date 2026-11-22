<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewCustomer;

use Modules\Access\Application\Query\ListCustomers\CustomerSummary;
use Modules\Access\Public\Dto\AddressDto;

/**
 * What a staff member sees of one customer (amendment 43): everything the list shows, their
 * language, where they last shopped, and their address book in every store — the answer to "where
 * is my order going?".
 */
final readonly class CustomerDetails
{
    /**
     * @param  list<AddressDto>  $addresses
     */
    public function __construct(
        public CustomerSummary $customer,
        public string $locale,
        public string $lastStoreId,
        public array $addresses,
    ) {}
}
