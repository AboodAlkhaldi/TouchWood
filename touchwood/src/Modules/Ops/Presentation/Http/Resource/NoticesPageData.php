<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A store's notices, newest first (ops.md §1.7, §4): `next` the cursor for the following page (`?after=`), null on the last. */
#[TypeScript]
final class NoticesPageData extends Data
{
    /**
     * @param  list<NoticeData>  $notices
     */
    public function __construct(
        public array $notices,
        public ?string $next,
    ) {}
}
