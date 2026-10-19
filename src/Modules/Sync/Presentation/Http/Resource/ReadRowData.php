<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One read (Y1 Reads, Y2's header): kind, state, who asked (Q36), the counts (Q30), its reason (Q31), its
 * moments and how long it took in seconds.
 */
#[TypeScript]
final class ReadRowData extends Data
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $state,
        public string $askedBy,
        public bool $askedBySystem,
        public ?int $codes,
        public int $pricesChanged,
        public int $stockChanged,
        public int $skipped,
        public ?FailureData $failure,
        public string $queuedAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $tookSeconds,
    ) {}
}
