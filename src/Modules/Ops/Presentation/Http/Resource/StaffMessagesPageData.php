<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A store's staff messages, newest first (ops.md §1.8, §4): `next` the cursor for the following page (`?after=`), null on the last. */
#[TypeScript]
final class StaffMessagesPageData extends Data
{
    /**
     * @param  list<StaffMessageData>  $messages
     */
    public function __construct(
        public array $messages,
        public ?string $next,
    ) {}
}
