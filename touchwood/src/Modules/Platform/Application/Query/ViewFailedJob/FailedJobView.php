<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ViewFailedJob;

use Modules\Platform\Application\FailedJobs\FailedJob;

/**
 * One failed job and whether it can be put back on its queue (platform.md §3).
 */
final readonly class FailedJobView
{
    public function __construct(
        public FailedJob $job,
        public bool $retryable,
    ) {}
}
