<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSmsCountries;

use Modules\Platform\Public\Dto\TranslatedTextDto;

/**
 * One listed country: its ISO code, its name in both languages (PHP's, owner 2026-10-29), its calling
 * code and its provider.
 */
final readonly class SmsCountryRow
{
    public function __construct(
        public string $countryCode,
        public TranslatedTextDto $name,
        public string $callingCode,
        public string $provider,
    ) {}
}
