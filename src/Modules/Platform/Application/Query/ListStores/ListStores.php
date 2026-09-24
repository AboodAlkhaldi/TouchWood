<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListStores;

/**
 * The stores screen's read (frontend.md §3.5, E1).
 */
final readonly class ListStores
{
    public function __construct(
        /** The language the currency's symbol is written in when it has no sign of its own. */
        public string $locale = 'ar',
    ) {}
}
