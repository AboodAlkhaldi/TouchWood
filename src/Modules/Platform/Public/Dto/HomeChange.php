<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A figure's change against the period before (dashboard.md §3.4): `(now - before) ÷ before`, in percent to one
 * decimal, with ▲ or ▼ and the good or bad colour - good up for every figure of the dashboard. Nothing before and
 * something now reads "New"; nothing either time shows no change at all (`of()` answers null).
 */
#[TypeScript]
final readonly class HomeChange
{
    public function __construct(
        /** The change in percent, to one decimal; null: "New". */
        public ?float $percent,
    ) {}

    /** Null when both are 0: no change to show. */
    public static function of(int|float $now, int|float $before): ?self
    {
        if ($before == 0) {
            return $now == 0 ? null : new self(null);
        }

        return new self(round(($now - $before) / abs($before) * 100, 1));
    }
}
