<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\AddAddressCountry;

/**
 * Put a country on the address list (platform.md, stage 7 (a); owner, 2026-10-27 R1). Super Admin only.
 */
final readonly class AddAddressCountry
{
    public function __construct(
        public string $countryCode,
    ) {}
}
