<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A store's segments, newest first (ops.md §4.3, §4.4): `next` the cursor for the following page (`?after=`), null on the last. */
#[TypeScript]
final class SegmentsPageData extends Data
{
    /**
     * @param  list<SegmentChoiceData>  $segments
     */
    public function __construct(
        public array $segments,
        public ?string $next,
    ) {}
}
