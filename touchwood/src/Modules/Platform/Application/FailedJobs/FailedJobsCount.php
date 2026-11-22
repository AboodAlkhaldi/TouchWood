<?php

declare(strict_types=1);

namespace Modules\Platform\Application\FailedJobs;

use Modules\Platform\Public\Contracts\MenuCount;

/**
 * How many failed jobs wait (frontend.md E7): beside "Failed jobs" in the menu, and on the admin home
 * while any does. Asked only for someone the entry is offered to — a holder of platform.jobs.manage.
 */
final readonly class FailedJobsCount implements MenuCount
{
    public function __construct(
        private FailedJobs $failedJobs,
    ) {}

    public function count(): int
    {
        return $this->failedJobs->count();
    }
}
