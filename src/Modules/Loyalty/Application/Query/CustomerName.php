<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use Modules\Access\Public\Dto\CustomerDto;

/**
 * Who a points account belongs to, as Loyalty's staff screens show them (loyalty.md §4.1): the
 * name - which a store's staff see for every customer with points there (access.md amendment
 * 72(c)) - and the email only for a customer whose own page the reader may open (§9.5 P1). An
 * anonymized customer, or one Access no longer has, is "Deleted customer": no name, no link.
 */
final readonly class CustomerName
{
    public function __construct(
        public string $customerId,
        public ?string $name,
        public ?string $email,
        public bool $deleted,
    ) {}

    public static function of(string $customerId, ?CustomerDto $customer, bool $mayView): self
    {
        if ($customer === null || $customer->anonymized) {
            return new self($customerId, null, null, true);
        }

        return new self($customerId, trim($customer->firstName.' '.$customer->lastName), $mayView ? $customer->email : null, false);
    }
}
