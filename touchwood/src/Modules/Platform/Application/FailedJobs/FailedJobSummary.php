<?php

declare(strict_types=1);

namespace Modules\Platform\Application\FailedJobs;

use DateTimeImmutable;

/**
 * One failed job as the list needs it (frontend.md E7): read without its payload or its whole
 * error, so a long backlog stays light to page through.
 */
final readonly class FailedJobSummary
{
    public function __construct(
        public string $id,
        public string $connection,
        public string $queue,
        public string $className,
        /** As FailedJob::triesAllowed(): 0 no limit, null the worker's number. */
        public ?int $triesAllowed,
        public string $errorLine,
        public DateTimeImmutable $failedAt,
    ) {}
}
