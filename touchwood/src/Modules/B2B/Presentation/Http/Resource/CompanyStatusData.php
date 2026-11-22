<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The company as it stands (b2b.md §1.1): the latest values sent, its status and what it was told.
 */
#[TypeScript]
final class CompanyStatusData extends Data
{
    public function __construct(
        public string $id,
        public CompanyValuesData $details,
        /** PENDING, APPROVED, REJECTED or SUSPENDED. */
        public string $status,
        /** Why it was rejected, suspended or reinstated. */
        public ?string $statusReason,
        /** In the home store's time (HANDOFF §4). */
        public ?string $statusChangedAt,
        public bool $mayOrder,
    ) {}
}
