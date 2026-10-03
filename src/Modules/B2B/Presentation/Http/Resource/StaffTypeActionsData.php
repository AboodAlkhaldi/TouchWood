<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What this reader may do to the list shown, in its store (b2b.md §3.2) — B2B's answer.
 */
#[TypeScript]
final class StaffTypeActionsData extends Data
{
    public function __construct(
        public bool $mayReadCompanyTypes,
        public bool $mayReadDocumentTypes,
        public bool $mayAdd,
        public bool $mayUpdate,
        public bool $mayDeactivate,
        public bool $mayDeactivateIntoNew,
        public bool $mayTransfer,
        public bool $mayMarkReviewed,
    ) {}
}
