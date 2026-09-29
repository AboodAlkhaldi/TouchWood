<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListFailedJobs;

use Modules\Platform\Application\FailedJobs\FailedJob;
use Modules\Platform\Application\FailedJobs\FailedJobs;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The failed jobs screen's list (platform.md §3, frontend.md E7): what ran, when it failed, the tries
 * it was allowed, and the error's first line. The whole error is ViewFailedJob's.
 */
final readonly class ListFailedJobsHandler
{
    public const string PERMISSION = PlatformPermissions::JOBS_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private FailedJobs $failedJobs,
    ) {}

    /**
     * @return list<FailedJobRow>
     */
    public function handle(ListFailedJobs $query): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        return array_map(
            static fn (FailedJob $job): FailedJobRow => new FailedJobRow(
                $job->id, $job->className(), $job->failedAt, $job->triesAllowed(), $job->queue, $job->errorLine(),
            ),
            $this->failedJobs->all(),
        );
    }
}
