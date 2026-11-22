<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E6 - the audit log (frontend.md 3.5).
 */
#[TypeScript]
final class AuditLogPage extends Data
{
    /**
     * @param  list<AuditRow>  $entries
     * @param  list<string>  $actions  the actions that actually appear in the log this reader sees
     * @param  list<string>  $sources
     * @param  array<string, string|null>  $filters  what is being filtered by now
     * @param  array<string, string>  $actionLabels  each of those actions in the page's words, as
     *                                               its entries name it, so the filter offers the
     *                                               names people read in the log, not the keys
     */
    public function __construct(
        public array $entries,
        public array $actions,
        public array $sources,
        public array $filters,
        public ?string $nextOccurredAt,
        public ?int $nextId,
        public array $actionLabels,
    ) {}
}
