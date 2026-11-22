<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A kind of entry and how many are open, for the kind filter.
 */
#[TypeScript]
final class KindCountData extends Data
{
    public function __construct(
        public string $kind,
        public int $count,
    ) {}
}
