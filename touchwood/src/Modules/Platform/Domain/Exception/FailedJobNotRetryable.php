<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Retrying a job that failed on a queue other than the database one (platform.md §3): its push
 * could be lost, or survive the delete it goes with. It can only be deleted. None today.
 */
final class FailedJobNotRetryable extends PlatformError
{
    public function __construct(public readonly string $jobId)
    {
        parent::__construct("The failed job \"{$jobId}\" ran on a queue it cannot be put back on safely: delete it instead.");
    }

    public function type(): string
    {
        return 'platform.failed_job_not_retryable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['id' => $this->jobId];
    }
}
