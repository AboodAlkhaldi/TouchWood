<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What this reader may do to the company next (b2b.md §4.6) — B2B's answer, never the screen's.
 */
#[TypeScript]
final class StaffCompanyActionsData extends Data
{
    public function __construct(
        public bool $mayOpenDocuments,
        public bool $mayApprove,
        /** type_not_set or account_deleted: Approve is shown disabled, with the reason. */
        public ?string $approveRefusal,
        public bool $mayReject,
        public bool $maySuspend,
        public bool $mayReinstate,
        public bool $mayCorrectType,
        public bool $mayChooseOther,
    ) {}
}
