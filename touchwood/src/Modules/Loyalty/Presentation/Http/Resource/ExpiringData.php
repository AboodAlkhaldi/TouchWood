<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Modules\Loyalty\Application\Query\ExpiringNext;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** "Expiring next" (loyalty.md §4.1): the points ending on the soonest day, and that day (YYYY-MM-DD). */
#[TypeScript]
final class ExpiringData extends Data
{
    public function __construct(
        public int $points,
        public string $day,
    ) {}

    public static function of(?ExpiringNext $next): ?self
    {
        return $next === null ? null : new self($next->points, $next->day);
    }
}
