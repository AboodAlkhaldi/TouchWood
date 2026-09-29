<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One failed job on E7 (frontend.md 3.5).
 */
#[TypeScript]
final class FailedJobRowData extends Data
{
    public function __construct(
        public string $id,
        /** In the words of the module that owns it; its technical name when it has none. */
        public string $name,
        /** ISO 8601, UTC. */
        public string $failedAt,
        /** The tries Laravel allowed it — not the tries made; null when it set no limit. */
        public ?int $triesAllowed,
        public string $queue,
        public string $errorLine,
    ) {}
}
