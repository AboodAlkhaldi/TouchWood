<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A section of the staff list (frontend.md 3.3, C1).
 *
 * People are grouped by the store they work in, with one section for those who work in more than
 * one - the owner calls it Centralized - and admins in their own short section above the rest
 * (decided 2026-09-19).
 */
#[TypeScript]
final class StaffGroup extends Data
{
    /**
     * @param  list<StaffRow>  $staff
     */
    public function __construct(
        /** A store id, or "centralized", or "admins". */
        public string $key,
        /** Already in the language the panel is being read in. */
        public string $label,
        public array $staff,
    ) {}
}
