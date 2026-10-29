<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSmsCountries;

/**
 * The SMS countries page (access.md amendment 87): every listed country, sorted by name in the page's
 * language, and the providers a country may choose.
 */
final readonly class ListSmsCountries
{
    public function __construct(
        public string $locale,
    ) {}
}
