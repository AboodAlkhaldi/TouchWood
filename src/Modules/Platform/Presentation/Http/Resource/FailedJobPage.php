<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E7 — one failed job, with its whole error (frontend.md 3.5).
 */
#[TypeScript]
final class FailedJobPage extends Data
{
    public function __construct(
        public FailedJobRowData $job,
        /** The whole error, stack trace included: it may quote personal data. */
        public string $error,
    ) {}
}
