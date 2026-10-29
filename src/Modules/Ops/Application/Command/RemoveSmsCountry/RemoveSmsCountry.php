<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\RemoveSmsCountry;

/**
 * Take a country off the SMS countries list (access.md amendment 87). Super Admin only.
 */
final readonly class RemoveSmsCountry
{
    public function __construct(
        public string $countryCode,
    ) {}
}
