<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use Modules\Access\Public\Dto\CustomerDto;

/** A customer as staff see them on Sales's screens: a name only — "Deleted customer" once anonymized. */
final readonly class CustomerName
{
    public function __construct(
        public string $customerId,
        public ?string $name,
        public bool $deleted,
    ) {}

    public static function of(string $customerId, ?CustomerDto $customer): self
    {
        return $customer === null || $customer->anonymized
            ? new self($customerId, null, true)
            : new self($customerId, trim($customer->firstName.' '.$customer->lastName), false);
    }
}
