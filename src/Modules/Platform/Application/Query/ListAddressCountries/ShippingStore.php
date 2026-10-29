<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAddressCountries;

use Modules\Platform\Public\Dto\TranslatedTextDto;

/**
 * The store that ships to a country (platform.md §4.3 B1), named as the stores page names it; an off
 * store still counts as the country's (shipping.md §1.2).
 */
final readonly class ShippingStore
{
    public function __construct(
        public string $id,
        public string $code,
        public TranslatedTextDto $name,
        public bool $active,
    ) {}
}
