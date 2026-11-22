<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * A country a customer may choose in an address (platform.md, stage 7 (a)). Its names are PHP's
 * country names in both languages. `hasPlaces` says whether Platform holds a place list for it, so
 * the address form knows to offer pickers rather than typed fields (access.md amendment 76(d)).
 */
final readonly class AddressCountryDto
{
    public function __construct(
        public string $code,
        public TranslatedTextDto $name,
        public bool $hasPlaces,
    ) {}
}
