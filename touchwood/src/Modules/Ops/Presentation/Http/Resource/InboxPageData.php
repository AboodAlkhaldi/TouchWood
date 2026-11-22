<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A page of a bell, newest first (ops.md §1.2, §4): `next` the cursor to ask the following page with
 * (`?after=`), null on the last; `unread` the bell's number over all of the reader's entries.
 */
#[TypeScript]
final class InboxPageData extends Data
{
    /**
     * @param  list<InboxEntryData>  $entries
     */
    public function __construct(
        public array $entries,
        public ?string $next,
        public int $unread,
    ) {}
}
