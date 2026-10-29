<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The number on a bell (ops.md §1.2, §4), answered by the count and by every mark-read. */
#[TypeScript]
final class UnreadData extends Data
{
    public function __construct(
        public int $unread,
    ) {}
}
