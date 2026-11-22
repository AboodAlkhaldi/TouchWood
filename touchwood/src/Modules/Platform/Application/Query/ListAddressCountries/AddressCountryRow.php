<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAddressCountries;

use Modules\Platform\Public\Dto\TranslatedTextDto;

/**
 * One address country on the places pages (platform.md §4.3 B1): its own mark; whether it has a
 * place list (a region in use - what adding it to a store's ship-to list needs); the store that ships
 * there, off ones too; and the regions and cities in use.
 */
final readonly class AddressCountryRow
{
    public function __construct(
        public string $code,
        public TranslatedTextDto $name,
        public bool $retired,
        public bool $hasPlaces,
        public ?ShippingStore $store,
        public int $regions,
        public int $cities,
    ) {}
}
