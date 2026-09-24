<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListCustomers;

/**
 * The customers a staff member may see (spec §3.3): those whose home store is one of theirs. A
 * Super Admin sees everyone.
 *
 * $search matches part of a name, an email or a phone number. $accountType narrows to individuals
 * or to companies (frontend.md §3.7, G1); null is both.
 */
final readonly class ListCustomers
{
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $accountType = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
