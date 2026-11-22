<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

/** A page of a segment's members, by customer id, with the cursor to the next (null at the end). */
final readonly class MembersPage
{
    /**
     * @param  list<MemberRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextCustomerId,
    ) {}
}
