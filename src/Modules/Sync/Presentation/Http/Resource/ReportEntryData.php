<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One open entry of the Sync report (Y1 Problems), its first and last time seen.
 */
#[TypeScript]
final class ReportEntryData extends Data
{
    public function __construct(
        public int $id,
        public ReportedData $about,
        public string $firstSeenAt,
        public string $lastSeenAt,
    ) {}
}
