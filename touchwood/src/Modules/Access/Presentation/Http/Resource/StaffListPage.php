<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * C1 - the staff list (frontend.md 3.3).
 */
#[TypeScript]
final class StaffListPage extends Data
{
    /**
     * @param  list<StaffGroup>  $groups
     * @param  list<string>  $statuses  the statuses that can be filtered by
     */
    public function __construct(
        public array $groups,
        public int $total,
        public ?string $search,
        public ?string $status,
        public array $statuses,
        public bool $mayInvite,
    ) {}
}
