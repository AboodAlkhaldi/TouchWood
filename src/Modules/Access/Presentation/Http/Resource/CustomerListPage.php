<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * G1 - the customers a staff member may see (frontend.md §3.7).
 *
 * Those whose home store is one of theirs, and everyone for a Super Admin. Who that is, is Access's
 * answer: this only carries it.
 */
#[TypeScript]
final class CustomerListPage extends Data
{
    /**
     * @param  list<CustomerRow>  $customers  newest first
     * @param  list<string>  $statuses  the statuses that may be filtered by, as Access names them
     * @param  list<string>  $accountTypes  individual, company
     */
    public function __construct(
        public array $customers,
        public int $total,
        public int $page,
        public int $perPage,
        public ?string $search,
        public ?string $status,
        public ?string $accountType,
        public array $statuses,
        public array $accountTypes,
    ) {}
}
