<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\ChangeSmsCountryCallingCode;

/**
 * Correct a listed country's calling code, as the Super Admin types it (access.md amendment 87).
 * Super Admin only.
 */
final readonly class ChangeSmsCountryCallingCode
{
    public function __construct(
        public string $countryCode,
        public string $callingCode,
    ) {}
}
