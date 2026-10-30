<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\ChangeSmsCountryProvider;

/**
 * Send a listed country's SMS through another provider (access.md amendment 90). Super Admin only.
 */
final readonly class ChangeSmsCountryProvider
{
    public function __construct(
        public string $countryCode,
        public string $provider,
    ) {}
}
