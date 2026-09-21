<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestCustomerPhoneCode;

/**
 * The customer's own number, theirs to add or to change (spec §1.3). The code goes to this number.
 */
final readonly class RequestCustomerPhoneCode
{
    public function __construct(
        public string $phone,
    ) {}
}
