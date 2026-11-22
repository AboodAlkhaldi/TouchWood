<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * No failed job has that id (platform.md §3, §7.3): retried or deleted already — by somebody else
 * a moment ago, perhaps — or never there.
 */
final class FailedJobNotFound extends PlatformError
{
    public function __construct(public readonly string $jobId)
    {
        parent::__construct("No failed job has the id \"{$jobId}\".");
    }

    public function type(): string
    {
        return 'platform.failed_job_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['id' => $this->jobId];
    }
}
