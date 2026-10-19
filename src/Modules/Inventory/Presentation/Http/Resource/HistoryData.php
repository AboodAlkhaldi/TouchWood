<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A page of a size's history, newest first, and the cursor of the next.
 */
#[TypeScript]
final class HistoryData extends Data
{
    /**
     * @param  list<HistoryLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public ?string $next,
    ) {}
}
