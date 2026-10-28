<?php

declare(strict_types=1);

namespace Modules\B2B\Public\Dto;

/**
 * How many applications of one store were sent, and how many approved, in a range — for Ops's daily
 * report (b2b.md §2.1, amendment 34). Counts only: no company, no person.
 */
final readonly class ApplicationCountsDto
{
    public function __construct(
        /** Applications sent in the range: each send once, on the day it was sent — a send after a rejection counts again. */
        public int $submitted,
        /** Applications approved in the range, on the day of the decision, whatever day they were sent. */
        public int $approved,
    ) {}
}
