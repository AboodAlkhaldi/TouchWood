<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListCustomers;

/**
 * The customers a staff member may see (spec §3.3): those whose home store is one of theirs. A
 * Super Admin sees everyone.
 *
 * $search matches part of a name, an email or a phone number.
 */
final readonly class ListCustomers
{
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
