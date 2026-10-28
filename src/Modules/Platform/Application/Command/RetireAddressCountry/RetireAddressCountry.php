<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RetireAddressCountry;

/**
 * Take a country off the address list (stage 7 step 1, Q2, Q3). Super Admin only.
 */
final readonly class RetireAddressCountry
{
    public function __construct(
        public string $countryCode,
    ) {}
}
