<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

/** A page of staff's returns, newest first; `next` the return id Show More goes on after. */
final readonly class StaffReturnsPage
{
    /**
     * @param  list<StaffReturnRow>  $returns
     * @param  array<string, int>  $counts  each state's returns for the same stores, and CLOSED_ALL's, when asked; else none
     */
    public function __construct(
        public array $returns,
        public ?string $next,
        public array $counts = [],
    ) {}
}
