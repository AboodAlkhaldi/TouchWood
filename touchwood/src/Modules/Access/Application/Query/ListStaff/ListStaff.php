<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListStaff;

/**
 * The staff a reader may see (spec §3.3, amendment 9): those whose stores all lie within the
 * reader's own. An admin appears as a name and a role only, and a Super Admin not at all —
 * except to another Super Admin (amendment 43).
 */
final readonly class ListStaff
{
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
