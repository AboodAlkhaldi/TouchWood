<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * What a home card shows for one scope (platform.md §2.6): its numbers, a short list, and the
 * screen it all comes from.
 */
final readonly class HomeCardData
{
    /**
     * @param  list<HomeFigure>  $figures
     * @param  list<HomeRow>  $rows
     * @param  string|null  $rowsLabel  the key of the list's heading, under the card's words, when it has one
     * @param  string|null  $href  the card's whole screen
     */
    public function __construct(
        public array $figures,
        public array $rows = [],
        public ?string $rowsLabel = null,
        public ?string $href = null,
    ) {}
}
