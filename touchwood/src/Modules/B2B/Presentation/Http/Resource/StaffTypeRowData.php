<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One type of the list (b2b.md §1.3): its names, position and state; how many companies hold a
 * company type; whether a document type is required.
 */
#[TypeScript]
final class StaffTypeRowData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public int $position,
        public bool $active,
        /** HIDDEN or GREYED while deactivated. */
        public ?string $inactiveDisplay,
        public ?bool $required,
        public ?int $holders,
    ) {}
}
