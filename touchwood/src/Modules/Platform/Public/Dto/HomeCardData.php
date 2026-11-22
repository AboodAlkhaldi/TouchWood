<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Modules\Platform\Public\Enums\HomeSize;

/**
 * What a home card shows for one scope (platform.md §2.6): its numbers, a short list, and the
 * screen it all comes from - and, since the dashboard (dashboard.md §6.1), figures with their change
 * and trend, a panel per store (a chart, splits, a table), a button for the greeting row, and how
 * much of a row it takes.
 *
 * The figures' and the list's labels are keys, put into words by the home; everything the newer kinds
 * carry is already in the reader's language, since the home keeps what a card answered per language
 * (dashboard.md §7, D11).
 */
final readonly class HomeCardData
{
    /**
     * @param  list<HomeFigure>  $figures
     * @param  list<HomeRow>  $rows
     * @param  string|null  $rowsLabel  the key of the list's heading, under the card's words, when it has one
     * @param  string|null  $href  the card's whole screen
     * @param  list<HomeMetric>  $metrics
     * @param  list<HomeStorePanel>  $panels  one per store, the base store first
     */
    public function __construct(
        public array $figures,
        public array $rows = [],
        public ?string $rowsLabel = null,
        public ?string $href = null,
        public HomeSize $size = HomeSize::Half,
        public array $metrics = [],
        public array $panels = [],
        public ?HomeAction $action = null,
    ) {}
}
