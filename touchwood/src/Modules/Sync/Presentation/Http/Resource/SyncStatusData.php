<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The status line (sync.md §4.1 Y1, S8): the provider, the state (`WORKING`, `FAILING`, `NOT_WIRED`) with a
 * failure's reason, the last read and the last full read (Q29), a read running or waiting.
 */
#[TypeScript]
final class SyncStatusData extends Data
{
    public function __construct(
        public ?string $provider,
        public ?string $providerName,
        public string $state,
        public ?FailureData $failure,
        public ?string $lastReadAt,
        public ?string $lastFullReadAt,
        public ?ReadMomentData $running,
        public ?ReadMomentData $waiting,
    ) {}
}
