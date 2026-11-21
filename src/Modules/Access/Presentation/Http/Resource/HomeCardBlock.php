<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\HomeAction;
use Modules\Platform\Public\Dto\HomeMetric;
use Modules\Platform\Public\Dto\HomeStorePanel;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One card on the admin home, as a module registered it (platform.md §2.6), its words already in the
 * reader's language - since the dashboard (dashboard.md §6.1) with its size, its figures with their change and
 * trend, its panels per store and its greeting-row button.
 */
#[TypeScript]
final class HomeCardBlock extends Data
{
    public function __construct(
        /** `{module}.{key}`: unique on the page. */
        public string $key,
        public string $title,
        /** @var list<HomeFigureBlock> */
        public array $figures,
        /** @var list<HomeRowBlock> */
        public array $rows,
        public ?string $rowsLabel,
        public ?string $href,
        /** The words on the way to the card's whole screen ("View Companies"), with $href. */
        public ?string $openLabel,
        /** A HomeSize: how much of a row it takes on a wide screen. */
        public string $size = 'half',
        /** @var list<HomeMetric> */
        public array $metrics = [],
        /** @var list<HomeStorePanel> */
        public array $panels = [],
        public ?HomeAction $action = null,
    ) {}
}
