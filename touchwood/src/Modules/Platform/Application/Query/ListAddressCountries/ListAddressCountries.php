<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAddressCountries;

/**
 * The address countries page (platform.md §4.3 B1): every country, retired ones included, sorted by
 * name in the page's language.
 */
final readonly class ListAddressCountries
{
    public function __construct(
        public string $locale,
    ) {}
}
