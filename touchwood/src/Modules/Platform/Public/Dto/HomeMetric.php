<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A figure of the admin home with its change and trend (dashboard.md §2 B, E, F; §6.1's "money figure", which
 * counts and ratings share): its words, a line per store, and for a count across stores their total.
 */
#[TypeScript]
final readonly class HomeMetric
{
    /**
     * @param  list<HomeMetricLine>  $lines
     */
    public function __construct(
        /** Already in the reader's language. */
        public string $label,
        public array $lines,
        /** A count's total across the stores listed (§3.2); null for money, or one store. */
        public ?HomeMetricLine $total = null,
        /** The screen the figure comes from, for the same stores. */
        public ?string $href = null,
    ) {}
}
