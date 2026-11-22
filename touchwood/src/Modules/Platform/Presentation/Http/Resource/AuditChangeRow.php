<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One attribute an audited change touched (frontend.md 3.5, E6).
 */
#[TypeScript]
final class AuditChangeRow extends Data
{
    public function __construct(
        public string $attribute,
        /** Null for a personal field, which was recorded as changed and never with its values. */
        public ?string $from,
        public ?string $to,
        public bool $personal,
    ) {}
}
