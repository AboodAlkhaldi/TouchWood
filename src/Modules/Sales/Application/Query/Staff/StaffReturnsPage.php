<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

/** A page of staff's returns, newest first; `next` the return id Show More goes on after. */
final readonly class StaffReturnsPage
{
    /**
     * @param  list<StaffReturnRow>  $returns
     */
    public function __construct(
        public array $returns,
        public ?string $next,
    ) {}
}
