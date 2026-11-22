<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RestoreAddressCountry;

/**
 * Bring a retired country back onto the address list (stage 7 step 1, Q3). Super Admin only.
 */
final readonly class RestoreAddressCountry
{
    public function __construct(
        public string $countryCode,
    ) {}
}
