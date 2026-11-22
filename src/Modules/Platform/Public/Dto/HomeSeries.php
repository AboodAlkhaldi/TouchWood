<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A home chart's lines and points (dashboard.md §2 C; §6.1's "series"): points by day, by month for the Year.
 */
#[TypeScript]
final readonly class HomeSeries
{
    /**
     * @param  list<HomeSeriesLine>  $lines
     * @param  list<HomePoint>  $points
     */
    public function __construct(
        public array $lines,
        public array $points,
        public bool $byMonth = false,
    ) {}
}
