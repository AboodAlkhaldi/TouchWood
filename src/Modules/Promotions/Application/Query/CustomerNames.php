<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use Modules\Access\Public\Contracts\AccessApi;

/** The names of a page's customers, in one call to Access (amendment 70: at most 1000 ids — a page is fewer). */
final readonly class CustomerNames
{
    public function __construct(
        private AccessApi $access,
    ) {}

    /**
     * @param  list<string>  $customerIds
     * @return array<string, CustomerName>
     */
    public function of(array $customerIds): array
    {
        $names = [];

        foreach ($this->access->customers(array_values(array_unique($customerIds))) as $id => $customer) {
            $names[$id] = new CustomerName($id, $customer->firstName, $customer->lastName, $customer->anonymized);
        }

        return $names;
    }

    /**
     * One customer's name; one Access no longer knows reads as deleted.
     *
     * @param  array<string, CustomerName>  $names
     */
    public static function pick(array $names, string $customerId): CustomerName
    {
        return $names[$customerId] ?? new CustomerName($customerId, '', '', true);
    }
}
