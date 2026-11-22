<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListStores;

use Modules\Platform\Public\Dto\TranslatedTextDto;

/**
 * One of a store's ship-to countries on the stores page (platform.md §4.3 B4): its code and its name
 * in both languages.
 */
final readonly class ShipsToCountry
{
    public function __construct(
        public string $code,
        public TranslatedTextDto $name,
    ) {}
}
