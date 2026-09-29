<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListFailedJobs;

use DateTimeImmutable;

/**
 * One failed job in the list (frontend.md E7). The class is named for people by the screen, in the
 * words of the module that owns it.
 */
final readonly class FailedJobRow
{
    public function __construct(
        public string $id,
        public string $className,
        public DateTimeImmutable $failedAt,
        /** 0 no limit; null the worker's own number (FailedJob::triesAllowed). */
        public ?int $triesAllowed,
        public string $queue,
        /** At most FailedJob::ERROR_LINE_MAX characters. */
        public string $errorLine,
        /** Only a job that failed on the database queue is put back (platform.md §3). */
        public bool $retryable,
    ) {}
}
