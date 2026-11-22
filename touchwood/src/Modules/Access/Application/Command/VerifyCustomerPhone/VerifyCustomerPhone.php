<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyCustomerPhone;

/**
 * The code the customer received on the number they entered (spec §1.3).
 */
final readonly class VerifyCustomerPhone
{
    public function __construct(
        public string $code,
    ) {}
}
