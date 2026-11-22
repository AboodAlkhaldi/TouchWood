<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the staff company list (b2b.md §4.6). Times are the company's home store's (HANDOFF §4).
 */
#[TypeScript]
final class StaffCompanyRowData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public string $storeName,
        /** When the application waiting for a decision was sent; null when none waits. */
        public ?string $waitingSince,
        /** The waiting application's company type was deactivated since it was sent (§1.3). */
        public bool $typeDeactivatedSinceSent,
        public ?string $statusChangedAt,
    ) {}
}
