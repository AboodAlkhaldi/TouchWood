<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;

/** One member of a segment (promotions.md §1.5): the name only — nothing else of the account — and since when. */
final readonly class MemberRow
{
    public function __construct(
        public CustomerName $customer,
        public DateTimeImmutable $addedAt,
    ) {}
}
