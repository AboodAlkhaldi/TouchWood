<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why an entry's ⋯ item is drawn disabled (sync.md §4.1 Y1), in the page's language.
 */
#[TypeScript]
final class SyncReasonsData extends Data
{
    public function __construct(
        public string $sizeGone,
        public string $readFailedStays,
    ) {}
}
