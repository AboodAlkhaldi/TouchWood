<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\AddSmsCountry;

/**
 * Put a country on the SMS countries list, with its calling code as the Super Admin types it and the
 * provider that sends to it (owner, 2026-10-29; access.md amendment 90). Super Admin only.
 */
final readonly class AddSmsCountry
{
    public function __construct(
        public string $countryCode,
        public string $callingCode,
        public string $provider,
    ) {}
}
