<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the reader's daily report emails (ops.md §1.11, §4): its store (none: every store's), on or
 * off, the time, and the time zone it is read in.
 */
#[TypeScript]
final class DailyReportEmailData extends Data
{
    public function __construct(
        public ?string $storeId,
        public ?string $storeCode,
        public string $storeName,
        public bool $on,
        /** `HH:MM`. */
        public string $sendAt,
        public string $timezone,
    ) {}
}
