<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of a home figure (dashboard.md §2 B, §3.2): one store's figure - All Stores lists one line per store,
 * each in its own currency - or the total of a count across stores.
 */
#[TypeScript]
final readonly class HomeMetricLine
{
    /**
     * @param  list<int|float>  $trend  the period's points, oldest first, for the small trend line; none for no line
     */
    public function __construct(
        /** The store's name, already in words, when the figure lists every store; null for one store or a total. */
        public ?string $store,
        /** Null for a figure that cannot be worked out: an average with no order ("—"). */
        public ?HomeValue $value,
        public ?HomeChange $change = null,
        public array $trend = [],
        /** Words under the figure, already in the reader's language ("Refunds 120.00 SAR", D7). */
        public ?string $note = null,
    ) {}
}
