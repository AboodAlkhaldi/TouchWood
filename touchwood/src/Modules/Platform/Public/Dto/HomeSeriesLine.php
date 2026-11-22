<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of a home chart (dashboard.md §2 C): its key in the points, its words, its unit, and whether it is the
 * period before's, drawn dotted in a grey (§4).
 */
#[TypeScript]
final readonly class HomeSeriesLine
{
    public function __construct(
        public string $key,
        /** Already in the reader's language. */
        public string $label,
        /** HomeValue::COUNT or HomeValue::MONEY: a money line's points are its smallest units. */
        public string $unit,
        public bool $dotted = false,
        /** For a money line: an amount written as the panel writes it, so the page writes the points the same way. */
        public ?MoneyData $money = null,
    ) {}
}
