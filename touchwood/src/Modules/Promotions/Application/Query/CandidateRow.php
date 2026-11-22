<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

/** One customer the hand-picker offers: the name only, and whether already in the segment. */
final readonly class CandidateRow
{
    public function __construct(
        public CustomerName $customer,
        public bool $member,
    ) {}
}
