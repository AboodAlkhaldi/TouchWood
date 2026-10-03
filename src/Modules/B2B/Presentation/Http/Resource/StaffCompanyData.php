<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The company as it is now (b2b.md §1.1, §4.6): the latest values sent, its status and why, and who
 * changed it last. Times are its home store's (HANDOFF §4).
 */
#[TypeScript]
final class StaffCompanyData extends Data
{
    public function __construct(
        public string $id,
        public CompanyValuesData $values,
        public string $status,
        /** The status a reinstatement returns it to, while suspended (§4.1). */
        public ?string $statusBeforeSuspension,
        public ?string $statusReason,
        public ?string $statusChangedAt,
        /** The staff member who changed the status last, by name. */
        public ?string $statusChangedBy,
        public string $storeName,
        public bool $mayOrder,
    ) {}
}
