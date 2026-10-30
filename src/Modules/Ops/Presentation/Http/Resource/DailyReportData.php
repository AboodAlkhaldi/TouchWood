<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One daily report kept (ops.md §1.11, §4): its store (none: every store's), its day, who made it -
 * named as the reader may see them - when, whether the email took it, and where to download it.
 */
#[TypeScript]
final class DailyReportData extends Data
{
    public function __construct(
        public string $id,
        public ?string $storeCode,
        public string $storeName,
        /** `YYYY-MM-DD`: the store's own day, or the base store's for every store. */
        public string $day,
        public bool $pastDay,
        public string $madeBy,
        /** UTC, with its offset. */
        public string $madeAt,
        public ?string $emailedAt,
        public string $language,
        public int $bytes,
        public string $downloadUrl,
    ) {}
}
